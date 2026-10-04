import { ref } from 'vue'
import { defineStore } from 'pinia'
import { Book } from '@/entities/book.ts'
import { fetchBooks, reportUploadCut, uploadBook } from '@/api/book.ts'
import { useBookStore } from '@/stores/book.ts'

type State = 'draft' | 'pending' | 'running' | 'transferring' | 'paused' | 'ended' | 'error'

export interface QueueItem {
  state: State
  book: Book
  file: File
  progress: number
  log: Array<string>
  failed: boolean
  controller?: AbortController
}

export function statusLabel(element: QueueItem): string {
  switch (element.state) {
    case 'draft':
      return 'Brouillon'

    case 'pending':
      return 'En attente'

    case 'running':
      return 'En cours'

    case 'transferring':
      return 'Transfert'

    case 'paused':
      return 'En pause'

    case 'error':
      return 'Erreur'

    case 'ended':
      return 'Terminé'
  }
}

export const useQueueStore = defineStore('queue', {
  state: () => {
    const uploads = ref(new Array<QueueItem>())

    return { uploads }
  },
  getters: {
    drafts(state) {
      return state.uploads.filter(({ state }) => state === 'draft')
    },
    running(state) {
      return state.uploads.some(({ state }) => state === 'running' || state === 'transferring')
    },
    launchable(state) {
      return state.uploads.some(({ state }) => state === 'draft' || state === 'pending')
    },
  },
  actions: {
    enqueue(book: Book, file: File): void {
      this.uploads.push({ state: 'draft', book, file, progress: 0, log: [], failed: false })
    },

    canRemove(item: QueueItem): boolean {
      return item.state === 'paused' || !this.running
    },

    remove(item: QueueItem): void {
      if (!this.canRemove(item)) {
        return
      }

      if (item.state === 'running') {
        item.controller?.abort()
      }

      this.uploads = this.uploads.filter((upload) => upload !== item)
    },

    setState(item: QueueItem, state: State): void {
      item.state = state
    },

    canPause(item: QueueItem): boolean {
      return item.state === 'draft' || item.state === 'pending' || item.state === 'running'
    },

    pause(item: QueueItem): void {
      if (!this.canPause(item)) {
        return
      }

      if (item.state === 'running') {
        item.controller?.abort()
      }

      item.state = 'paused'
      item.log.push('En pause.')
    },

    resume(item: QueueItem): void {
      if (item.state !== 'paused') {
        return
      }

      item.state = 'pending'
      item.log.push('Remis en attente.')
    },

    pauseAll(): void {
      for (const item of this.uploads) {
        if (item.state === 'running') {
          item.controller?.abort()
        }

        if (item.state === 'draft' || item.state === 'pending' || item.state === 'running') {
          item.state = 'paused'
          item.log.push('En pause.')
        }
      }
    },

    async submit(): Promise<void> {
      if (this.running) {
        return
      }

      for (const item of this.uploads) {
        if (item.state === 'draft') {
          item.state = 'pending'
        }
      }

      const batch = this.uploads.filter(({ state }) => state === 'pending')

      for (const item of batch) {
        if (item.state !== 'pending') {
          continue
        }

        await this.startUpload(item)
      }
    },

    startUpload(item: QueueItem): Promise<void> {
      item.state = 'running'
      item.progress = 0
      item.failed = false
      item.log = ['Envoi du fichier…']

      const controller = new AbortController()
      item.controller = controller

      return new Promise((resolve) => {
        void this.attemptUpload(item, controller, () => resolve())
      })
    },

    async upload(item: QueueItem): Promise<void> {
      item.state = 'running'
      item.progress = 0
      item.failed = false
      item.log = ['Envoi du fichier…']

      const controller = new AbortController()
      item.controller = controller

      await this.attemptUpload(item, controller, () => {})
    },

    async attemptUpload(
      item: QueueItem,
      controller: AbortController,
      onPhaseChange: () => void,
    ): Promise<void> {
      let transferred = false

      try {
        const book = await uploadBook(item.book, item.file, {
          signal: controller.signal,
          onProgress: (percent) => {
            if (item.controller !== controller) {
              return
            }

            item.progress = percent

            if (percent >= 100 && !transferred) {
              transferred = true
              item.state = 'transferring'
              item.log.push('Transfert vers le serveur de stockage…')
              onPhaseChange()
            }
          },
        })

        item.book = book
        item.state = 'ended'
        item.progress = 100
        item.log.push('Enregistrement en base.')
        item.log.push('Notification Discord envoyée.')
        item.log.push('Terminé.')
        useBookStore().$patch({ books: await fetchBooks() })
        onPhaseChange()
      } catch {
        if (controller.signal.aborted) {
          onPhaseChange()
          return
        }

        item.state = 'ended'
        item.failed = true
        item.log.push('Échec lors du stockage, admin averti.')
        void reportUploadCut(item.book)
        onPhaseChange()
      } finally {
        if (item.controller === controller) {
          item.controller = undefined
        }
      }
    },
  },
})
