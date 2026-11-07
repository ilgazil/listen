import type { Book } from '@/entities/book.ts'
import { collectTags, loose } from '@/helpers/tags.ts'

// Suggestion de saga : le nom sert à l'affichage, l'id à ouvrir la page.
export type SagaSuggestion = { id: string, name: string }

// Suggestions de recherche de l'index, regroupées par nature et plafonnées :
// titres et sagas sont distingués (groupes et plafonds séparés).
export type SearchSuggestions = {
  titles: Array<string>
  sagas: Array<SagaSuggestion>
  authors: Array<string>
  narrators: Array<string>
  tags: Array<string>
}

const GROUP_LIMIT = 5

export function searchSuggestions(books: Array<Book>, query: string): SearchSuggestions {
  const needle = loose(query)

  if (!needle) {
    return { titles: [], sagas: [], authors: [], narrators: [], tags: [] }
  }

  const titles: Array<string> = []
  const sagas: Array<SagaSuggestion> = []
  const authors: Array<string> = []
  const narrators: Array<string> = []
  const seenTitles = new Set<string>()
  const seenSagas = new Set<string>()
  const seenAuthors = new Set<string>()
  const seenNarrators = new Set<string>()

  const room = (list: Array<unknown>): boolean => list.length < GROUP_LIMIT

  for (const book of books) {
    const title = loose(book.title)
    if (title && title.includes(needle) && !seenTitles.has(title) && room(titles)) {
      seenTitles.add(title)
      titles.push(book.title)
    }

    const saga = book.saga
    if (saga && saga.id && !seenSagas.has(saga.id) && room(sagas)) {
      const name = loose(saga.name)
      if (name && name.includes(needle)) {
        seenSagas.add(saga.id)
        sagas.push({ id: saga.id, name: saga.name })
      }
    }

    const author = loose(book.author)
    if (author && author.includes(needle) && !seenAuthors.has(author) && room(authors)) {
      seenAuthors.add(author)
      authors.push(book.author)
    }

    for (const narrator of book.narrators) {
      const key = loose(narrator)
      if (key && key.includes(needle) && !seenNarrators.has(key) && room(narrators)) {
        seenNarrators.add(key)
        narrators.push(narrator)
      }
    }
  }

  // Tags : collectTags trie déjà par popularité (puis alphabétiquement).
  const tags = collectTags(books)
    .filter((tag) => loose(tag.name).includes(needle))
    .slice(0, GROUP_LIMIT)
    .map((tag) => tag.name)

  return { titles, sagas, authors, narrators, tags }
}
