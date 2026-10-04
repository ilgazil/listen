import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import BookCard from '@/components/BookCard.vue'
import { Book } from '@/entities/book'

function bookWith(cover: string): Book {
  const book = new Book()
  book.title = 'Titre'
  book.author = 'Auteur'
  book.cover = cover
  return book
}

describe('BookCard', () => {
  it('affiche la couverture quand elle est définie', () => {
    const wrapper = mount(BookCard, { props: { book: bookWith('https://example.com/c.jpg') } })

    expect(wrapper.find('img').attributes('src')).toBe('https://example.com/c.jpg')
    expect(wrapper.find('.placeholder').exists()).toBe(false)
  })

  it('affiche le placeholder quand aucune couverture n’est définie', () => {
    const wrapper = mount(BookCard, { props: { book: bookWith('') } })

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('.placeholder').exists()).toBe(true)
  })

  it('bascule sur le placeholder si la couverture ne charge pas', async () => {
    const wrapper = mount(BookCard, { props: { book: bookWith('https://example.com/broken.jpg') } })

    await wrapper.find('img').trigger('error')

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('.placeholder').exists()).toBe(true)
  })
})
