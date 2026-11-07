import type { Book, Work } from '@/entities/book.ts'

// Un tag de bibliothèque collecté : `name` est la première écriture rencontrée
// (celle qui s'affiche), `count` le nombre de livres qui le portent.
export type LibraryTag = {
  name: string
  count: number
}

// Normalisation « loose » d'un tag : casse et accents ignorés, comme la
// recherche des fiches — « Épique » et « epique » sont le même tag.
export function loose(value: string): string {
  return value
    .trim()
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
}

function hasTagLoose(book: Book, tag: string): boolean {
  const needle = loose(tag)

  return !!needle && book.tags.some((candidate) => loose(candidate) === needle)
}

// Le livre porte-t-il ce tag (comparaison insensible à la casse et aux accents) ?
export function hasTag(book: Book, tag: string): boolean {
  return hasTagLoose(book, tag)
}

// Filtre une liste de livres sur un tag (no-op si le filtre est vide).
export function filterByTag(books: Array<Book>, tag: string): Array<Book> {
  if (!loose(tag)) {
    return books
  }

  return books.filter((book) => hasTagLoose(book, tag))
}

// L'œuvre (saga ou livre isolé) porte-t-elle ce tag ? Pour une saga, il suffit
// qu'UN de ses livres le porte — l'affichage de la saga montre alors tous ses
// livres, indépendamment du tag sélectionné.
export function workHasTag(work: Work, tag: string): boolean {
  if (work.type === 'book') {
    return hasTagLoose(work.book, tag)
  }

  return work.books.some((book) => hasTagLoose(book, tag))
}

// Collecte les tags de la bibliothèque (pas de table dédiée en base : les tags
// vivent sur les livres) — dédupliqués sans casse ni accents, triés par
// popularité puis alphabétiquement.
export function collectTags(books: Array<Book>): Array<LibraryTag> {
  const seen = new Map<string, LibraryTag>()

  for (const book of books) {
    for (const tag of book.tags) {
      const key = loose(tag)

      if (!key) {
        continue
      }

      const existing = seen.get(key)

      if (existing) {
        existing.count++
      } else {
        seen.set(key, { name: tag.trim(), count: 1 })
      }
    }
  }

  return Array.from(seen.values()).sort(
    (a, b) => b.count - a.count || a.name.localeCompare(b.name),
  )
}
