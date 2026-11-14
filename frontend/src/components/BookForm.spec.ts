import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import BookForm from '@/components/BookForm.vue'
import { Book } from '@/entities/book.ts'
import { ScrapError } from '@/api/book.ts'
import { useBookStore } from '@/stores/book.ts'

const mocks = vi.hoisted(() => ({
  search: vi.fn(),
  searchByUrl: vi.fn(),
  scrapTags: vi.fn(),
}))

// L'API est mockée pour piloter le timing du scrap des tags (le reste de la
// surface du module est conservé : le store de bibliothèque l'importe aussi).
vi.mock('@/api/book.ts', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/book.ts')>()),
  search: mocks.search,
  searchByUrl: mocks.searchByUrl,
  scrapTags: mocks.scrapTags,
}))

function audibleBook(): Book {
  const book = new Book()

  book.scraper = 'audible'
  book.scrapId = 'B01M2WSG6T'
  book.title = "L'apprenti assassin"
  book.cover = 'cover.jpg'
  book.author = 'Robin Hobb'
  book.narrators = ['Sylvain Agaësse']
  book.tags = []
  book.runtime = '17:48'

  return book
}

function libraryBook(tags: Array<string>): Book {
  const book = audibleBook()

  book.tags = tags

  return book
}

async function searchSuggestion(wrapper: VueWrapper): Promise<void> {
  mocks.search.mockResolvedValue([audibleBook()])
  await wrapper.find('.search-input input').setValue('assassin')
  await vi.advanceTimersByTimeAsync(200)
  await flushPromises()
}

// Chaque test a son propre store (les suggestions de tags lisent la
// bibliothèque) : on pré-remplit les livres du store avant le montage.
function mountForm(books: Array<Book> = []): VueWrapper {
  const pinia = createPinia()
  setActivePinia(pinia)
  useBookStore().books = books

  return mount(BookForm, { global: { plugins: [pinia] } })
}

function chipNames(wrapper: VueWrapper): Array<string> {
  return wrapper.findAll('.tags-box .tag').map((chip) => chip.text().replace(/×/g, '').trim())
}

function tagInput(wrapper: VueWrapper) {
  return wrapper.find('.tags-box input')
}

describe('BookForm — enregistrement et scrap des tags', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.clearAllMocks()
  })

  it('désactive le bouton enregistrer tant que les tags sont cherchés', async () => {
    const wrapper = mountForm()
    await searchSuggestion(wrapper)

    let resolveTags!: (tags: string[]) => void
    mocks.scrapTags.mockImplementation(
      () => new Promise((resolve) => { resolveTags = resolve }),
    )

    await wrapper.find('.suggestion').trigger('click')
    await flushPromises()

    const save = () => wrapper.find('button[type="submit"]')
    expect(save().attributes('disabled')).toBeDefined()

    resolveTags(['Fantasy', 'Épique'])
    await flushPromises()

    expect(save().attributes('disabled')).toBeUndefined()
    // Les tags fraîchement scrapés remplissent le cadre : une chip par tag.
    const chips = wrapper.findAll('.tags-box .tag')
    expect(chips.length).toBe(2)
    expect(chips[0].text()).toContain('Fantasy')
    expect(chips[1].text()).toContain('Épique')
  })

  it('bloque aussi la soumission par Entrée pendant le scrap', async () => {
    const wrapper = mountForm()
    await searchSuggestion(wrapper)

    let resolveTags!: (tags: string[]) => void
    mocks.scrapTags.mockImplementation(
      () => new Promise((resolve) => { resolveTags = resolve }),
    )

    await wrapper.find('.suggestion').trigger('click')
    await flushPromises()

    // La touche Entrée soumet le formulaire même avec le bouton désactivé :
    // le handler doit ignorer la soumission tant que les tags arrivent.
    await wrapper.find('form').trigger('submit')
    expect(wrapper.emitted('submit')).toBeUndefined()

    resolveTags(['Fantasy'])
    await flushPromises()
    await wrapper.find('form').trigger('submit')
    expect(wrapper.emitted('submit')?.length).toBe(1)
  })
})

describe('BookForm — champ tags à chips', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.clearAllMocks()
  })

  it('crée un tag par Entrée à partir de la saisie libre', async () => {
    const wrapper = mountForm()
    const input = tagInput(wrapper)

    await input.setValue('Nouveau')
    await input.trigger('keydown', { key: 'Enter' })

    expect(chipNames(wrapper)).toEqual(['Nouveau'])
    // La saisie est vidée après l'ajout.
    expect((input.element as HTMLInputElement).value).toBe('')
  })

  it('n ajoute jamais deux fois le même tag malgré la casse ou les accents', async () => {
    const wrapper = mountForm()
    const input = tagInput(wrapper)

    await input.setValue('Fantasy')
    await input.trigger('keydown', { key: 'Enter' })
    await input.setValue('FANTASY')
    await input.trigger('keydown', { key: 'Enter' })
    await input.setValue('fantasy ')
    await input.trigger('keydown', { key: 'Enter' })

    expect(chipNames(wrapper)).toEqual(['Fantasy'])
  })

  it('marque les tags absents de la bibliothèque comme nouveaux', async () => {
    const wrapper = mountForm([libraryBook(['Fantasy'])])
    const input = tagInput(wrapper)

    await input.setValue('Fantasy')
    await input.trigger('keydown', { key: 'Enter' })
    await input.setValue('Nouveau')
    await input.trigger('keydown', { key: 'Enter' })

    const chips = wrapper.findAll('.tags-box .tag')
    expect(chips).toHaveLength(2)
    // « Fantasy » existe dans la bibliothèque : pas de marqueur.
    expect(chips[0].classes()).not.toContain('new')
    expect(chips[0].attributes('title')).toBeUndefined()
    // « Nouveau » n'existe pas : bordure pointillée + info-bulle.
    expect(chips[1].classes()).toContain('new')
    expect(chips[1].attributes('title')).toBe('Nouveau tag')
  })

  it('reconnaît un tag connu sans casse ni accents', async () => {
    const wrapper = mountForm([libraryBook(['Fantasy'])])
    const input = tagInput(wrapper)

    await input.setValue('FANTASY')
    await input.trigger('keydown', { key: 'Enter' })

    expect(wrapper.findAll('.tags-box .tag')[0].classes()).not.toContain('new')
  })

  it('affiche « Recherche en cours » pendant une recherche sans vignettes', async () => {
    let resolveSearch!: (books: Book[]) => void
    mocks.search.mockImplementation(
      () => new Promise((resolve) => { resolveSearch = resolve }),
    )

    const wrapper = mountForm()
    await wrapper.find('.search-input input').setValue('hobb')
    await flushPromises()

    // Dès la frappe (et pendant le fetch) : jamais de « Aucun résultat » prématuré.
    expect(wrapper.find('.suggestions .empty').text()).toContain('Recherche en cours')
    await vi.advanceTimersByTimeAsync(200)
    await flushPromises()
    expect(wrapper.find('.suggestions .empty').text()).toContain('Recherche en cours')

    resolveSearch([audibleBook()])
    await flushPromises()

    // Résultats arrivés : vignettes affichées, message parti.
    expect(wrapper.findAll('.suggestion')).toHaveLength(1)
    expect(wrapper.find('.suggestions .empty').exists()).toBe(false)
  })

  it('laisse les vignettes affichées pendant la recherche suivante', async () => {
    mocks.search.mockResolvedValue([audibleBook()])
    const wrapper = mountForm()
    await wrapper.find('.search-input input').setValue('hobb')
    await vi.advanceTimersByTimeAsync(200)
    await flushPromises()
    expect(wrapper.findAll('.suggestion')).toHaveLength(1)

    // Recherche suivante en vol : les vignettes restent, aucun message d'attente.
    let resolveSearch!: (books: Book[]) => void
    mocks.search.mockImplementation(
      () => new Promise((resolve) => { resolveSearch = resolve }),
    )
    await wrapper.find('.search-input input').setValue('hobb fusse')
    await flushPromises()
    await vi.advanceTimersByTimeAsync(200)
    await flushPromises()

    expect(wrapper.findAll('.suggestion')).toHaveLength(1)
    expect(wrapper.find('.suggestions .empty').exists()).toBe(false)

    // Vraiment aucun résultat : le message reprend la main.
    resolveSearch([])
    await flushPromises()
    expect(wrapper.find('.suggestions .empty').text()).toContain('Aucun résultat')
  })

  it('recherche par termes par défaut, par URL en mode « URL »', async () => {
    mocks.search.mockResolvedValue([audibleBook()])
    const wrapper = mountForm()

    // Toggle présent, « Recherche » actif par défaut.
    const toggleButtons = wrapper.findAll('.toggle button')
    expect(toggleButtons).toHaveLength(2)
    expect(toggleButtons[0].classes()).toContain('active')

    await wrapper.find('.search-input input').setValue('hobb')
    await vi.advanceTimersByTimeAsync(200)
    await flushPromises()

    expect(mocks.search).toHaveBeenCalledTimes(1)
    expect(mocks.searchByUrl).not.toHaveBeenCalled()

    // Bascule « URL » : placeholder dédié et saisie réinitialisée (départ propre,
    // la recherche par termes n'est plus appelée).
    mocks.searchByUrl.mockResolvedValue([audibleBook()])
    await toggleButtons[1].trigger('click')
    expect((wrapper.find('.search-input input').element as HTMLInputElement).value).toBe('')
    expect(wrapper.find('.search-input input').attributes('placeholder')).toBe(
      "Coller l'URL d'une fiche...",
    )

    await wrapper.find('.search-input input').setValue('https://www.audible.fr/pd/Book/B01M2WSG6T')
    await vi.advanceTimersByTimeAsync(200)
    await flushPromises()

    expect(mocks.searchByUrl).toHaveBeenCalledWith(
      'https://www.audible.fr/pd/Book/B01M2WSG6T',
      expect.anything(),
    )
    expect(mocks.search).toHaveBeenCalledTimes(1)
    expect(wrapper.findAll('.suggestion')).toHaveLength(1)
  })

  it('affiche le message dédié quand l URL ne donne rien', async () => {
    mocks.searchByUrl.mockResolvedValue([])
    const wrapper = mountForm()

    await wrapper.findAll('.toggle button')[1].trigger('click')
    await wrapper.find('.search-input input').setValue('https://www.audible.fr/pd/Book/B01M2WSG6T')
    await vi.advanceTimersByTimeAsync(200)
    await flushPromises()

    expect(wrapper.find('.suggestions .empty').text()).toBe(
      'Impossible de récupérer des métadonnées sur cette URL',
    )
  })

  it('affiche l erreur backend (anti-bot) avec le bouton Réessayer', async () => {
    mocks.searchByUrl.mockRejectedValue(
      new ScrapError(
        "Le scrap de la fiche a été bloqué par l'anti-bot d'Audible : réessaie dans quelques minutes.",
      ),
    )
    const wrapper = mountForm()

    await wrapper.findAll('.toggle button')[1].trigger('click')
    await wrapper.find('.search-input input').setValue('https://www.audible.fr/pd/Book/B01M2WSG6T')
    await vi.advanceTimersByTimeAsync(200)
    await flushPromises()

    expect(wrapper.find('.suggestions .empty').text()).toContain("l'anti-bot d'Audible")
    expect(wrapper.find('.suggestions .empty button').text()).toBe('Réessayer')
  })

  it('exclut des suggestions les tags déjà en chips', async () => {
    const wrapper = mountForm([libraryBook(['Fantasy', 'Aventure'])])
    const input = tagInput(wrapper)

    await input.setValue('Fantasy')
    await input.trigger('keydown', { key: 'Enter' })
    expect(chipNames(wrapper)).toEqual(['Fantasy'])

    // La bibliothèque propose Aventure mais pas Fantasy (déjà en chip).
    await input.trigger('focus')
    const names = wrapper.findAll('.tags-dropdown button').map((b) => b.text().trim())
    expect(names).toEqual(['Aventure'])
  })

  it('suggère les tags de la bibliothèque, hors des chips déjà saisies', async () => {
    const wrapper = mountForm([libraryBook(['Fantasy', 'Aventure']), libraryBook(['Épique'])])
    const input = tagInput(wrapper)

    // Focus sans saisie : tous les tags de la bibliothèque (tri alpha, la
    // collation française place « Épique » entre Aventure et Fantasy).
    await input.trigger('focus')
    let names = wrapper.findAll('.tags-dropdown button').map((b) => b.text().trim())
    expect(names).toEqual(['Aventure', 'Épique', 'Fantasy'])

    // Ajout de Fantasy via le dropdown : la suggestion disparaît des propositions.
    await wrapper.findAll('.tags-dropdown button')[2].trigger('mousedown')
    expect(chipNames(wrapper)).toEqual(['Fantasy'])
    names = wrapper.findAll('.tags-dropdown button').map((b) => b.text().trim())
    expect(names).toEqual(['Aventure', 'Épique'])
  })

  it('ajoute la suggestion active par Entrée après navigation clavier', async () => {
    const wrapper = mountForm([libraryBook(['Fantasy', 'Aventure'])])
    const input = tagInput(wrapper)

    await input.trigger('focus')
    await input.trigger('keydown', { key: 'ArrowDown' })
    await input.trigger('keydown', { key: 'Enter' })

    // La navigation part de la première suggestion : Aventure (tri alpha).
    expect(chipNames(wrapper)).toEqual(['Aventure'])
  })

  it('retire un tag via la croix de sa chip', async () => {
    const wrapper = mountForm([libraryBook(['Fantasy', 'Aventure'])])
    const input = tagInput(wrapper)

    await input.trigger('focus')
    await input.trigger('keydown', { key: 'ArrowDown' })
    await input.trigger('keydown', { key: 'Enter' })
    await input.trigger('keydown', { key: 'ArrowDown' })
    await input.trigger('keydown', { key: 'Enter' })
    expect(chipNames(wrapper)).toEqual(['Aventure', 'Fantasy'])

    await wrapper.findAll('.tags-box .tag .remove')[0].trigger('click')

    expect(chipNames(wrapper)).toEqual(['Fantasy'])
  })

  it('envoie les chips dans le payload de soumission', async () => {
    const wrapper = mountForm()
    const input = tagInput(wrapper)

    await input.setValue('Fantasy')
    await input.trigger('keydown', { key: 'Enter' })
    await input.setValue('Épique')
    await input.trigger('keydown', { key: 'Enter' })

    await wrapper.find('form').trigger('submit')

    const payload = wrapper.emitted('submit')?.[0]?.[0] as Book
    expect(payload.tags).toEqual(['Fantasy', 'Épique'])
  })
})
