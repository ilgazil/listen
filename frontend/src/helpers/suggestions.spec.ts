import { expect, it } from 'vitest'
import { Book } from '@/entities/book.ts'
import { Saga } from '@/entities/saga.ts'
import { searchSuggestions } from '@/helpers/suggestions.ts'

function book(
  title: string,
  opts: { saga?: string; sagaId?: string; author?: string; narrators?: Array<string>; tags?: Array<string> } = {},
): Book {
  const b = new Book()

  b.title = title
  b.author = opts.author ?? ''
  b.narrators = opts.narrators ?? []
  b.tags = opts.tags ?? []

  if (opts.saga) {
    const saga = new Saga()
    saga.id = opts.sagaId ?? opts.saga
    saga.name = opts.saga
    b.saga = saga
  }

  return b
}

it('retourne des groupes vides sans requête', () => {
  const s = searchSuggestions([book('Le Gambit du magicien', { author: 'David Eddings' })], '   ')

  expect(s).toEqual({ titles: [], sagas: [], authors: [], narrators: [], tags: [] })
})

it('distingue titres, sagas, auteurs et narrateurs', () => {
  const books = [
    book('Le Gambit du magicien', {
      saga: 'La Belgariade',
      author: 'David Eddings',
      narrators: ['Franck Dacquin'],
      tags: ['Fantasy'],
    }),
  ]
  const s = searchSuggestions(books, 'a')

  expect(s.titles).toEqual(['Le Gambit du magicien'])
  expect(s.sagas).toEqual([{ id: 'La Belgariade', name: 'La Belgariade' }])
  expect(s.authors).toEqual(['David Eddings'])
  expect(s.narrators).toEqual(['Franck Dacquin'])
  expect(searchSuggestions(books, 'gambit').sagas).toEqual([])
  expect(searchSuggestions(books, 'belgariade').titles).toEqual([])
})

it('plafonne chaque groupe à 5', () => {
  const titles = [1, 2, 3, 4, 5, 6].map((n) => book(`Titre ${n}`))
  expect(searchSuggestions(titles, 'titre').titles).toHaveLength(5)

  const sagas = [1, 2, 3, 4, 5, 6].map((n) => book(`Livre ${n}`, { saga: `Saga ${n}`, sagaId: `saga-${n}` }))
  const s = searchSuggestions(sagas, 'saga')

  expect(s.sagas).toEqual([
    { id: 'saga-1', name: 'Saga 1' },
    { id: 'saga-2', name: 'Saga 2' },
    { id: 'saga-3', name: 'Saga 3' },
    { id: 'saga-4', name: 'Saga 4' },
    { id: 'saga-5', name: 'Saga 5' },
  ])
  expect(s.titles).toEqual([])
})

it('déduplique les sagas par identifiant', () => {
  const books = [book('Tome 1', { saga: 'La Belgariade', sagaId: '42' }), book('Tome 2', { saga: 'La Belgariade', sagaId: '42' })]

  expect(searchSuggestions(books, 'belgar').sagas).toEqual([{ id: '42', name: 'La Belgariade' }])
})

it('déduplique les titres sans casse ni accents', () => {
  const books = [book('Épique'), book('epique'), book('ÉPIQUE')]

  expect(searchSuggestions(books, 'epi').titles).toEqual(['Épique'])
})

it('classe les tags par popularité', () => {
  const books = [book('A', { tags: ['Fantasy'] }), book('B', { tags: ['Fantasy', 'Aventure'] })]

  expect(searchSuggestions(books, 'a').tags).toEqual(['Fantasy', 'Aventure'])
})
