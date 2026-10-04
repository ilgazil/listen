import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  clearBooksCache,
  fetchBooks,
  normalizeSearch,
  reportUploadCut,
  search,
  updateBook,
  uploadBook,
} from '@/api/book.ts'

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  clearBooksCache()
})

class FakeXMLHttpRequest {
  static last: FakeXMLHttpRequest | null = null

  upload = {
    onprogress: null as
      ((event: { loaded: number; total: number; lengthComputable: boolean }) => void) | null,
  }
  response: unknown = null
  responseType = ''
  status = 0
  onload: (() => void) | null = null
  onerror: (() => void) | null = null
  onabort: (() => void) | null = null
  method = ''
  url = ''
  sent: FormData | null = null

  constructor() {
    FakeXMLHttpRequest.last = this
  }

  open(method: string, url: string): void {
    this.method = method
    this.url = url
  }

  send(body: FormData): void {
    this.sent = body
  }

  abort(): void {
    this.onabort?.()
  }

  progress(loaded: number, total: number): void {
    this.upload.onprogress?.({ loaded, total, lengthComputable: true })
  }

  succeed(status: number, body: unknown): void {
    this.status = status
    this.response = body
    this.onload?.()
  }

  fail(): void {
    this.onerror?.()
  }
}

function stubXhr(): void {
  FakeXMLHttpRequest.last = null
  vi.stubGlobal('XMLHttpRequest', FakeXMLHttpRequest)
}

describe('normalizeSearch', () => {
  it('met en minuscules et retire les accents', () => {
    expect(normalizeSearch('ÉLÉGANT')).toBe('elegant')
  })

  it('remplace les caractères non alphanumériques par des espaces', () => {
    expect(normalizeSearch('Le Seigneur Des Anneaux!')).toBe('le seigneur des anneaux')
  })

  it('retire les caractères isolés', () => {
    expect(normalizeSearch("l'épée de vérité")).toBe('epee de verite')
  })
})

describe('fetchBooks', () => {
  it('mappe le contrat API (member) vers des entités Book', async () => {
    const raw = {
      member: [
        {
          id: '1fichier123',
          scraper: 'audible',
          scrap_id: 'B000123',
          title: 'Le Seigneur des Anneaux',
          cover: 'https://example.com/c.jpg',
          author: 'J.R.R. Tolkien',
          narrators: ['Un narrateur'],
          runtime: '11:22:33',
          ratings: 4.6,
          saga: 'Terre du Milieu',
          tome: '1.5',
        },
      ],
    }
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify(raw)))

    const books = await fetchBooks()

    expect(books).toHaveLength(1)
    const book = books[0]
    expect(book.id).toBe('1fichier123')
    expect(book.scrapId).toBe('B000123')
    expect(book.title).toBe('Le Seigneur des Anneaux')
    expect(book.ratings).toBe(4.6)
    expect(book.saga?.name).toBe('Terre du Milieu')
    expect(book.saga?.tome).toBe('1.5')
  })

  it('retourne une liste vide si member est absent', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({})))
    expect(await fetchBooks()).toEqual([])
  })

  it('réutilise la liste en cache quand le serveur répond 304', async () => {
    const raw = {
      member: [
        {
          id: 'cached-1',
          title: 'Caché',
          cover: 'https://example.com/c.jpg',
          author: 'Un auteur',
        },
      ],
    }
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(
        new Response(JSON.stringify(raw), { status: 200, headers: { ETag: '"abc"' } }),
      )
      .mockResolvedValueOnce(new Response(null, { status: 304 }))

    const first = await fetchBooks()
    const second = await fetchBooks()

    expect(second).toBe(first)
    expect(second[0].title).toBe('Caché')

    // Le 2e appel revalide avec If-None-Match et jamais de cache HTTP local.
    const [, init] = fetchMock.mock.calls[1]
    expect(init?.headers).toEqual({ 'If-None-Match': '"abc"' })
    expect(init?.cache).toBe('no-cache')
  })

  it('rafraîchit le cache et mémorise le nouvel etag quand le serveur répond 200', async () => {
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(
        new Response(JSON.stringify({ member: [{ id: 'old' }] }), {
          status: 200,
          headers: { ETag: '"v1"' },
        }),
      )
      .mockResolvedValueOnce(
        new Response(JSON.stringify({ member: [{ id: 'new' }, { id: 'new2' }] }), {
          status: 200,
          headers: { ETag: '"v2"' },
        }),
      )

    const first = await fetchBooks()
    const second = await fetchBooks()

    expect(second).not.toBe(first)
    expect(second).toHaveLength(2)
    expect(second[0].id).toBe('new')

    const [, init] = fetchMock.mock.calls[1]
    expect(init?.headers).toEqual({ 'If-None-Match': '"v1"' })
  })
})

describe('search', () => {
  it('retourne une liste vide sans pattern', async () => {
    expect(await search('')).toEqual([])
  })

  it('normalise et encode le pattern dans la requête', async () => {
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValue(new Response(JSON.stringify([])))

    await search('Harry Potter!')

    expect(fetchMock).toHaveBeenCalledWith('/api/scrap?pattern=harry%20potter')
  })
})

describe('uploadBook', () => {
  it('envoie le multipart et retourne le Book sérialisé', async () => {
    stubXhr()
    const { Book } = await import('@/entities/book.ts')
    const book = new Book()
    book.title = 'Uploadé'

    const promise = uploadBook(book, new File(['zip'], 'book.zip'))

    const xhr = FakeXMLHttpRequest.last!
    expect(xhr.method).toBe('POST')
    expect(xhr.url).toBe('/api/upload')

    xhr.succeed(201, { id: 'new-id', title: 'Uploadé' })

    const result = await promise
    expect(result.id).toBe('new-id')
    expect(xhr.sent).toBeInstanceOf(FormData)
    expect(xhr.sent?.get('title')).toBe('Uploadé')
  })

  it('rapporte la progression de l’upload', async () => {
    stubXhr()
    const onProgress = vi.fn()
    const { Book } = await import('@/entities/book.ts')

    const promise = uploadBook(new Book(), new File(['zip'], 'book.zip'), { onProgress })

    const xhr = FakeXMLHttpRequest.last!
    xhr.progress(128, 512)
    expect(onProgress).toHaveBeenLastCalledWith(25)
    xhr.progress(512, 512)
    expect(onProgress).toHaveBeenLastCalledWith(100)

    xhr.succeed(201, {})
    await promise
  })

  it('lève une erreur avec le message du serveur si le statut n’est pas 201', async () => {
    stubXhr()
    const { Book } = await import('@/entities/book.ts')

    const promise = uploadBook(new Book(), new File(['zip'], 'book.zip'))

    FakeXMLHttpRequest.last!.succeed(502, { error: 'Upload to 1Fichier failed.' })

    await expect(promise).rejects.toThrow('Upload to 1Fichier failed.')
  })

  it('lève une erreur réseau si le transfert échoue', async () => {
    stubXhr()
    const { Book } = await import('@/entities/book.ts')

    const promise = uploadBook(new Book(), new File(['zip'], 'book.zip'))

    FakeXMLHttpRequest.last!.fail()

    await expect(promise).rejects.toThrow("Erreur réseau pendant l'envoi.")
  })

  it('peut être interrompu via un AbortController', async () => {
    stubXhr()
    const { Book } = await import('@/entities/book.ts')
    const controller = new AbortController()

    const promise = uploadBook(new Book(), new File(['zip'], 'book.zip'), {
      signal: controller.signal,
    })

    controller.abort()

    await expect(promise).rejects.toThrow('Envoi interrompu.')
  })
})

describe('updateBook', () => {
  it('envoie le payload JSON et retourne le Book mis à jour', async () => {
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValue(
        new Response(JSON.stringify({ id: 'id-1', title: 'MAJ', ratings: 4.9 }), { status: 200 }),
      )

    const { Book } = await import('@/entities/book.ts')
    const book = new Book()
    book.title = 'MAJ'

    const result = await updateBook('id-1', book)

    expect(result.ratings).toBe(4.9)
    const [, init] = fetchMock.mock.calls[0]
    expect(init?.method).toBe('PUT')
    expect(init?.headers).toEqual({ 'Content-Type': 'application/json' })
  })
})

describe('reportUploadCut', () => {
  it('poste le payload du livre', async () => {
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValue(new Response(null, { status: 204 }))

    const { Book } = await import('@/entities/book.ts')
    const book = new Book()
    book.title = 'Livre coupé en upload'

    await reportUploadCut(book)

    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('/api/report-upload-cut')
    expect(init?.method).toBe('POST')
    expect(init?.headers).toEqual({ 'Content-Type': 'application/json' })
    expect(JSON.parse(init?.body as string)).toMatchObject({
      title: 'Livre coupé en upload',
    })
  })

  it('lève une erreur si le signalement échoue', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(null, { status: 500 }))
    const { Book } = await import('@/entities/book.ts')

    await expect(reportUploadCut(new Book())).rejects.toThrow('Échec du signalement (500)')
  })
})
