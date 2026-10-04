import { describe, expect, it, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { Book } from '@/entities/book.ts'
import { statusLabel, useQueueStore, type QueueItem } from '@/stores/queue.ts'

vi.mock('@/api/book.ts', () => ({
  uploadBook: vi.fn(),
  fetchBooks: vi.fn(),
  reportUploadCut: vi.fn(),
}))

import { fetchBooks, reportUploadCut, uploadBook } from '@/api/book.ts'

function makeFile(): File {
  return new File(['zip'], 'book.zip', { type: 'application/zip' })
}

function makeBook(title = 'Mon livre'): Book {
  const book = new Book()
  book.title = title
  return book
}

function makeDraft(title?: string): QueueItem {
  return { state: 'draft', book: makeBook(title), file: makeFile(), progress: 0, log: [], failed: false }
}

describe('queue store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(uploadBook).mockReset()
    vi.mocked(fetchBooks).mockReset()
    vi.mocked(reportUploadCut).mockReset()
  })

  describe('statusLabel', () => {
    it('retourne le libellé français de chaque état', () => {
      const states: Array<[QueueItem['state'], string]> = [
        ['draft', 'Brouillon'],
        ['pending', 'En attente'],
        ['running', 'En cours'],
        ['transferring', 'Transfert'],
        ['paused', 'En pause'],
        ['error', 'Erreur'],
        ['ended', 'Terminé'],
      ]

      for (const [state, label] of states) {
        expect(
          statusLabel({ state, book: makeBook(), file: makeFile(), progress: 0, log: [], failed: false }),
        ).toBe(label)
      }
    })
  })

  it('enqueue ajoute un brouillon et remove le retire', () => {
    const queue = useQueueStore()
    const draft = makeDraft()

    queue.enqueue(draft.book, draft.file)
    expect(queue.uploads).toHaveLength(1)
    expect(queue.drafts).toHaveLength(1)

    queue.remove(queue.uploads[0])
    expect(queue.uploads).toHaveLength(0)
  })

  it('setState met à jour l’état sans dupliquer l’item', () => {
    const queue = useQueueStore()
    const draft = makeDraft()
    queue.enqueue(draft.book, draft.file)

    const item = queue.uploads[0]
    queue.setState(item, 'pending')

    expect(queue.uploads).toHaveLength(1)
    expect(queue.uploads[0].state).toBe('pending')
  })

  it('upload passe en running puis ended, rapporté la progression et rafraîchit la bibliothèque', async () => {
    const uploaded = makeBook('Envoyé')
    vi.mocked(uploadBook).mockImplementation(async (_book, _file, options) => {
      options?.onProgress?.(42)
      options?.onProgress?.(100)
      return uploaded
    })
    vi.mocked(fetchBooks).mockResolvedValue([uploaded])

    const queue = useQueueStore()
    const draft = makeDraft()
    queue.enqueue(draft.book, draft.file)

    await queue.upload(queue.uploads[0])

    expect(uploadBook).toHaveBeenCalledTimes(1)
    expect(fetchBooks).toHaveBeenCalledTimes(1)
    expect(queue.uploads[0].state).toBe('ended')
    expect(queue.uploads[0].failed).toBe(false)
    expect(queue.uploads[0].progress).toBe(100)
    expect(queue.uploads[0].log).toContain('Transfert vers le serveur de stockage…')
    expect(queue.uploads[0].log.at(-1)).toBe('Terminé.')
    expect(queue.uploads[0].book.title).toBe('Envoyé')
    expect(queue.running).toBe(false)
  })

  it('un échec d’envoi est considéré terminé avec le message dédié', async () => {
    vi.mocked(uploadBook).mockRejectedValue(new Error('Upload to 1Fichier failed.'))

    const queue = useQueueStore()
    const draft = makeDraft()
    queue.enqueue(draft.book, draft.file)

    await queue.upload(queue.uploads[0])

    expect(queue.uploads[0].state).toBe('ended')
    expect(queue.uploads[0].failed).toBe(true)
    expect(queue.uploads[0].log.at(-1)).toBe('Échec lors du stockage, admin averti.')
    expect(fetchBooks).not.toHaveBeenCalled()
    expect(reportUploadCut).toHaveBeenCalledTimes(1)
    expect(reportUploadCut).toHaveBeenCalledWith(queue.uploads[0].book)
  })

  it('pause place un item non lancé en pause quand la file est inactive', () => {
    const queue = useQueueStore()
    const draft = makeDraft()
    queue.enqueue(draft.book, draft.file)

    queue.pause(queue.uploads[0])

    expect(queue.uploads[0].state).toBe('paused')
    expect(queue.uploads[0].log.at(-1)).toBe('En pause.')
  })

  it('pause est sans effet sur un item terminé ou en erreur', () => {
    const queue = useQueueStore()
    queue.enqueue(makeDraft().book, makeDraft().file)
    queue.setState(queue.uploads[0], 'ended')
    queue.pause(queue.uploads[0])
    expect(queue.uploads[0].state).toBe('ended')

    queue.setState(queue.uploads[0], 'error')
    queue.pause(queue.uploads[0])
    expect(queue.uploads[0].state).toBe('error')
  })

  it('pause un item en attente même si un autre upload est en cours', () => {
    vi.mocked(uploadBook).mockImplementation(() => new Promise(() => {}))

    const queue = useQueueStore()
    const first = makeDraft()
    const second = makeDraft('Second')
    queue.enqueue(first.book, first.file)
    queue.enqueue(second.book, second.file)

    void queue.submit()
    expect(queue.uploads[0].state).toBe('running')

    queue.pause(queue.uploads[1])
    expect(queue.uploads[1].state).toBe('paused')
  })

  it('au transfert (100%), l’item passe en transferring, n’est plus pausable et la file reste active', async () => {
    let resolveUpload!: (book: Book) => void
    vi.mocked(uploadBook).mockImplementation(async (_book, _file, options) => {
      options?.onProgress?.(100)
      return new Promise((resolve) => {
        resolveUpload = resolve
      })
    })

    const queue = useQueueStore()
    const draft = makeDraft()
    queue.enqueue(draft.book, draft.file)

    const uploading = queue.upload(queue.uploads[0])

    expect(queue.uploads[0].state).toBe('transferring')
    expect(queue.running).toBe(true)
    expect(queue.canPause(queue.uploads[0])).toBe(false)
    expect(queue.uploads[0].log.at(-1)).toBe('Transfert vers le serveur de stockage…')

    resolveUpload(makeBook('Envoyé'))
    await uploading

    expect(queue.uploads[0].state).toBe('ended')
    expect(queue.running).toBe(false)
  })

  it('pause interrompt l’upload en cours et le passe en paused', async () => {
    vi.mocked(uploadBook).mockImplementation((_book, _file, options) => {
      return new Promise((_resolve, reject) => {
        options?.signal?.addEventListener('abort', () => reject(new Error('Upload aborted.')))
      })
    })

    const queue = useQueueStore()
    const draft = makeDraft()
    queue.enqueue(draft.book, draft.file)

    const submission = queue.submit()
    expect(queue.uploads[0].state).toBe('running')

    queue.pause(queue.uploads[0])
    expect(queue.uploads[0].state).toBe('paused')

    await submission
    expect(queue.uploads[0].state).toBe('paused')
    expect(queue.uploads[0].controller).toBeUndefined()
  })

  it('resume repasse un item en paused à pending', () => {
    const queue = useQueueStore()
    const draft = makeDraft()
    queue.enqueue(draft.book, draft.file)
    queue.setState(queue.uploads[0], 'paused')

    queue.resume(queue.uploads[0])

    expect(queue.uploads[0].state).toBe('pending')
    expect(queue.uploads[0].log.at(-1)).toBe('Remis en attente.')
  })

  it('une erreur pendant l’envoi est sans effet sur un item terminé en erreur', async () => {
    const queue = useQueueStore()
    queue.enqueue(makeDraft().book, makeDraft().file)
    queue.setState(queue.uploads[0], 'error')
    expect(queue.running).toBe(false)
  })

  it('submit envoie tous les brouillons séquentiellement', async () => {
    vi.mocked(uploadBook).mockResolvedValue(makeBook('Envoyé'))
    vi.mocked(fetchBooks).mockResolvedValue([])

    const queue = useQueueStore()
    const first = makeDraft()
    const second = makeDraft('Second')
    queue.enqueue(first.book, first.file)
    queue.enqueue(second.book, second.file)

    await queue.submit()

    expect(uploadBook).toHaveBeenCalledTimes(2)
    expect(queue.uploads.every((item) => item.state === 'ended')).toBe(true)
  })

  it('submit passe au suivant dès que l’upload précédent est terminé, le transfert continue en parallèle', async () => {
    let settleFirst!: () => void
    let first = true
    vi.mocked(uploadBook).mockImplementation((_book, _file, options) => {
      options?.onProgress?.(100)

      if (first) {
        first = false
        return new Promise<Book>((resolve) => {
          settleFirst = () => resolve(makeBook('Premier'))
        })
      }

      return Promise.resolve(makeBook('Second'))
    })
    vi.mocked(fetchBooks).mockResolvedValue([])

    const queue = useQueueStore()
    queue.enqueue(makeDraft().book, makeDraft().file)
    queue.enqueue(makeDraft('Second').book, makeDraft('Second').file)

    await queue.submit()

    expect(uploadBook).toHaveBeenCalledTimes(2)
    expect(queue.uploads[0].state).toBe('transferring')
    expect(queue.uploads[1].state).toBe('ended')
    expect(queue.running).toBe(true)

    settleFirst()
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(queue.uploads[0].state).toBe('ended')
    expect(queue.running).toBe(false)
  })

  it('submit se termine avec les items relancés pendant la file laissés en attente', async () => {
    const resolvers: Array<() => void> = []
    vi.mocked(uploadBook).mockImplementation(() => {
      return new Promise<Book>((resolve) => {
        resolvers.push(() => resolve(makeBook('Envoyé')))
      })
    })
    vi.mocked(fetchBooks).mockResolvedValue([])

    const queue = useQueueStore()
    queue.enqueue(makeDraft().book, makeDraft().file)
    queue.enqueue(makeDraft('Second').book, makeDraft('Second').file)

    queue.pause(queue.uploads[1])
    expect(queue.launchable).toBe(true)

    const submission = queue.submit()
    expect(queue.uploads[0].state).toBe('running')

    queue.resume(queue.uploads[1])
    expect(queue.uploads[1].state).toBe('pending')

    resolvers[0]()
    await submission

    expect(queue.uploads[0].state).toBe('ended')
    expect(queue.uploads[1].state).toBe('pending')
    expect(uploadBook).toHaveBeenCalledTimes(1)

    const relaunch = queue.submit()
    expect(queue.uploads[1].state).toBe('running')
    resolvers[1]()
    await relaunch

    expect(queue.uploads[1].state).toBe('ended')
    expect(uploadBook).toHaveBeenCalledTimes(2)
  })

  it('remove est interdit quand la file est en cours, sauf si l’élement est en pause', async () => {
    vi.mocked(uploadBook).mockImplementation(() => new Promise(() => {}))

    const queue = useQueueStore()
    queue.enqueue(makeDraft().book, makeDraft().file)
    queue.enqueue(makeDraft('Second').book, makeDraft('Second').file)
    queue.pause(queue.uploads[1])

    void queue.submit()
    expect(queue.uploads[0].state).toBe('running')
    expect(queue.uploads[1].state).toBe('paused')

    queue.remove(queue.uploads[0])
    expect(queue.uploads).toHaveLength(2)

    queue.remove(queue.uploads[1])
    expect(queue.uploads).toHaveLength(1)
  })

  it('pauseAll aborte l’upload en cours et met toute la file en pause', async () => {
    vi.mocked(uploadBook).mockImplementation((_book, _file, options) => {
      return new Promise((_resolve, reject) => {
        options?.signal?.addEventListener('abort', () => reject(new Error('Upload aborted.')))
      })
    })

    const queue = useQueueStore()
    queue.enqueue(makeDraft().book, makeDraft().file)
    queue.enqueue(makeDraft('Second').book, makeDraft('Second').file)

    const submission = queue.submit()
    expect(queue.uploads[0].state).toBe('running')

    queue.pauseAll()

    expect(queue.uploads.every((item) => item.state === 'paused')).toBe(true)
    expect(queue.running).toBe(false)
    expect(queue.uploads.every((item) => item.log.at(-1) === 'En pause.')).toBe(true)

    await submission
    expect(queue.uploads.every((item) => item.state === 'paused')).toBe(true)
  })

  it('pauseAll ne touche pas aux items en transfert, terminés ou en erreur', () => {
    const queue = useQueueStore()
    queue.enqueue(makeDraft().book, makeDraft().file)
    queue.enqueue(makeDraft('Second').book, makeDraft('Second').file)
    queue.enqueue(makeDraft('Third').book, makeDraft('Third').file)
    queue.setState(queue.uploads[0], 'ended')
    queue.setState(queue.uploads[1], 'error')
    queue.setState(queue.uploads[2], 'transferring')

    queue.pauseAll()

    expect(queue.uploads[0].state).toBe('ended')
    expect(queue.uploads[1].state).toBe('error')
    expect(queue.uploads[2].state).toBe('transferring')
  })
})
