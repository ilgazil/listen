import { ref } from 'vue'
import { defineStore } from 'pinia'
import { Book, isSagaBook, type SagaBook, type Work } from '@/entities/book.ts'
import type { Saga } from '@/entities/saga.ts'

export const useBookStore = defineStore('book', {
  state: () => {
    const books = ref(new Array<Book>())

    return { books }
  },
  getters: {
    orderedBooks(state) {
      const books = new Array(...state.books)

      books.sort((a, b) => a.title.localeCompare(b.title))

      return books
    },
    fromSaga() {
      return (saga: Required<Book>['saga']) => {
        const books = this.orderedBooks
          .filter(isSagaBook)
          .filter((book) => book.saga.id === saga.id)

        books.sort((a, b) => a.saga.tome.localeCompare(b.saga.tome, undefined, { numeric: true }))

        return books
      }
    },
    sagas(state) {
      const sagas = state.books.filter(isSagaBook).reduce((collection, { saga }) => {
        if (
          !collection.find(
            ({ name }) => name.trim().toLowerCase() === saga.name.trim().toLowerCase(),
          )
        ) {
          collection.push(saga)
        }

        return collection
      }, new Array<Saga>())

      sagas.sort((a, b) => a.name.localeCompare(b.name))

      return sagas
    },
    works() {
      const sagaMap = new Map<string, Array<SagaBook>>()
      const normalBooks = new Array<Book>()

      for (const book of this.books) {
        if (!isSagaBook(book)) {
          normalBooks.push(book)
          continue
        }

        const key = book.saga.name.trim().toLowerCase()
        const group = sagaMap.get(key)

        if (group) {
          group.push(book)
        } else {
          sagaMap.set(key, [book])
        }
      }

      const works = new Array<Work>()

      for (const group of sagaMap.values()) {
        if (group.length === 1) {
          normalBooks.push(group[0])
          continue
        }

        group.sort((a, b) => a.saga.tome.localeCompare(b.saga.tome, undefined, { numeric: true }))

        works.push({ type: 'saga', saga: group[0].saga, books: group })
      }

      for (const book of normalBooks) {
        works.push({ type: 'book', book })
      }

      works.sort((a, b) => {
        const aName = a.type === 'saga' ? a.saga.name : a.book.title
        const bName = b.type === 'saga' ? b.saga.name : b.book.title

        return aName.localeCompare(bName)
      })

      return works
    },
    sagaById() {
      return (id: string) => this.sagas.find((saga) => saga.id === id)
    },
  },
})
