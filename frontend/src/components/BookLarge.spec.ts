import { describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import BookLarge from '@/components/BookLarge.vue'
import { Book } from '@/entities/book.ts'

function book(tags: Array<string>): Book {
  const b = new Book()

  b.title = 'Le Gambit du magicien'
  b.cover = 'cover.jpg'
  b.author = 'David Eddings'
  b.tags = tags

  return b
}

function router(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div />' } },
      { path: '/edit/:id', name: 'edit', component: { template: '<div />' } },
    ],
  })
}

function mountLarge(tags: Array<string>, options: { linkTags?: boolean; router?: Router } = {}) {
  return mount(BookLarge, {
    props: { book: book(tags), linkTags: options.linkTags },
    global: {
      plugins: options.router ? [options.router] : [],
      stubs: { RouterLink: true },
    },
  })
}

describe('BookLarge — pastille tags', () => {
  it('affiche « 1 tag » au singulier avec la liste au survol', () => {
    const wrapper = mountLarge(['Fantasy'])

    expect(wrapper.find('.tags').text()).toContain('1 tag')
    expect(wrapper.findAll('.tag').map((s) => s.text())).toEqual(['Fantasy'])
  })

  it('affiche « N tags » au pluriel avec tous les tags listés', () => {
    const wrapper = mountLarge(['Fantasy', 'Aventure', 'Épique'])

    expect(wrapper.find('.tags').text()).toContain('3 tags')
    expect(wrapper.findAll('.tag').map((s) => s.text())).toEqual([
      'Fantasy',
      'Aventure',
      'Épique',
    ])
  })

  it('masque la pastille quand le livre n a pas de tags', () => {
    const wrapper = mountLarge([])

    expect(wrapper.find('.tags').exists()).toBe(false)
  })
})

describe('BookLarge — tags cliquables', () => {
  it('renvoie vers l index avec le tag sélectionné (?tag=…)', async () => {
    const r = router()
    await r.push('/')
    const wrapper = mountLarge(['Fantasy', 'Aventure'], { router: r })

    await wrapper.findAll('.tag')[1].trigger('click')
    await flushPromises()

    expect(r.currentRoute.value.fullPath).toBe('/?tag=Aventure')
  })

  it('preview du formulaire (link-tags false) : le clic est inerte', async () => {
    const r = router()
    await r.push('/')
    const wrapper = mountLarge(['Fantasy'], { router: r, linkTags: false })

    await wrapper.find('.tag').trigger('click')
    await flushPromises()

    expect(r.currentRoute.value.fullPath).toBe('/')
  })
})
