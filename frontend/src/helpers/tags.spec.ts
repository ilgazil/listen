import { describe, expect, it } from 'vitest'
import { Book, type SagaBook } from '@/entities/book.ts'
import type { Work } from '@/entities/book.ts'
import { Saga } from '@/entities/saga.ts'
import { collectTags, filterByTag, hasTag, loose, workHasTag } from '@/helpers/tags.ts'

function book(title: string, tags: Array<string>): Book {
  const book = new Book()

  book.title = title
  book.tags = tags

  return book
}

function sagaBook(title: string, tags: Array<string>, tome: string): SagaBook {
  const b = book(title, tags)
  const saga = new Saga()

  saga.id = 's1'
  saga.name = 'Assassin'
  saga.tome = tome

  b.saga = saga

  return b as SagaBook
}

describe('loose', () => {
  it('ignore la casse et les accents', () => {
    expect(loose(' Épique ')).toBe('epique')
    expect(loose('ÉPIQUE')).toBe(loose('epique'))
  })

  it('rend deux tags égaux malgré la casse ou les accents', () => {
    expect(loose('Fantasy')).toBe(loose('fantasy'))
    expect(loose('Àvènture')).toBe(loose('aventure'))
  })
})

describe('hasTag', () => {
  it('trouve un tag sans casse ni accent', () => {
    expect(hasTag(book('X', ['Fantasy', 'Épique']), 'epique')).toBe(true)
    expect(hasTag(book('X', ['Fantasy']), 'FANTASY')).toBe(true)
  })

  it('rejette un tag absent ou filtre vide', () => {
    expect(hasTag(book('X', ['Fantasy']), 'Aventure')).toBe(false)
    expect(hasTag(book('X', []), 'Fantasy')).toBe(false)
    expect(hasTag(book('X', ['Fantasy']), '  ')).toBe(false)
  })
})

describe('filterByTag', () => {
  const books = [book('A', ['Fantasy']), book('B', ['Aventure']), book('C', ['Épique'])]

  it('ne garde que les livres portant le tag', () => {
    expect(filterByTag(books, 'Fantasy').map((b) => b.title)).toEqual(['A'])
    expect(filterByTag(books, 'epique').map((b) => b.title)).toEqual(['C'])
  })

  it('retourne la liste telle quelle sans filtre', () => {
    expect(filterByTag(books, '')).toBe(books)
    expect(filterByTag(books, '   ')).toBe(books)
  })
})

describe('workHasTag', () => {
  const bookWork: Work = { type: 'book', book: book('A', ['Fantasy']) }

  it('délègue aux livres isolés', () => {
    expect(workHasTag(bookWork, 'fantasy')).toBe(true)
    expect(workHasTag(bookWork, 'Aventure')).toBe(false)
  })

  it('valide la saga dès qu un seul livre porte le tag', () => {
    const sagaWork: Work = {
      type: 'saga',
      saga: new Saga(),
      books: [sagaBook('A', ['Fantasy'], '1'), sagaBook('B', [], '2'), sagaBook('C', ['Aventure'], '3')],
    }

    expect(workHasTag(sagaWork, 'ÉPIQUE')).toBe(false)
    expect(workHasTag(sagaWork, 'Aventure')).toBe(true)
    expect(workHasTag(sagaWork, 'Fantasy')).toBe(true)
  })
})

describe('collectTags', () => {
  it('déduplique sans casse ni accents en gardant la première écriture', () => {
    const tags = collectTags([book('A', ['Fantasy']), book('B', ['fantasy']), book('C', ['FANTASY'])])

    expect(tags).toEqual([{ name: 'Fantasy', count: 3 }])
  })

  it('ignore les tags vides', () => {
    expect(collectTags([book('A', ['  ', ''])])).toEqual([])
  })

  it('trie par popularité puis alphabétiquement', () => {
    const tags = collectTags([
      book('A', ['Aventure', 'Fantasy']),
      book('B', ['Aventure']),
      book('C', ['Épique', 'Aventure']),
      book('D', ['Fantasy']),
    ])

    expect(tags.map((t) => t.name)).toEqual(['Aventure', 'Fantasy', 'Épique'])
    expect(tags.map((t) => t.count)).toEqual([3, 2, 1])
  })
})
