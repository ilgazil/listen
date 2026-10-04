import { Saga } from '@/entities/saga.ts'

export class Book {
  id = ''

  scraper: 'audible' | 'lizzie' = 'audible'
  scrapId = ''

  title = ''
  cover = ''
  author = ''

  narrators = new Array<string>()

  runtime = ''
  ratings = 0

  saga?: Saga

  get hasRuntime(): boolean {
    return this.runtime !== '00:00'
  }
}

export type SagaBook = Book & Required<Pick<Book, 'saga'>>

export function isSagaBook(book: Book): book is SagaBook {
  return !!book.saga
}

export type SagaWork = {
  type: 'saga'
  saga: Saga
  books: Array<SagaBook>
}

export type BookWork = {
  type: 'book'
  book: Book
}

export type Work = SagaWork | BookWork

function normalize(value: string): string {
  return value
    .trim()
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
}

export function bookMatches(book: Book, query: string): boolean {
  const terms = normalize(query).split(/\s+/).filter(Boolean)

  if (terms.length === 0) {
    return true
  }

  const haystack = normalize(
    [book.title, book.author, book.saga?.name ?? '', ...book.narrators].join(' '),
  )

  return terms.every((term) => haystack.includes(term))
}

export function workMatches(work: Work, query: string): boolean {
  if (work.type === 'book') {
    return bookMatches(work.book, query)
  }

  return bookMatches(work.books[0], query) || work.books.some((book) => bookMatches(book, query))
}
