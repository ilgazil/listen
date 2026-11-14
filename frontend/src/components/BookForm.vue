<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue'
import { search, scrapTags } from '@/api/book.ts'
import { Book } from '@/entities/book.ts'
import { Saga } from '@/entities/saga.ts'
import { collectTags, loose } from '@/helpers/tags.ts'
import { useBookStore } from '@/stores/book.ts'
import BookLarge from '@/components/BookLarge.vue'
import BookCard from '@/components/BookCard.vue'
import IconSave from '@/components/icons/IconSave.vue'
import IconClose from '@/components/icons/IconClose.vue'
import IconLoading from '@/components/icons/IconLoading.vue'
import IconAudible from '@/components/icons/IconAudible.vue'
import IconLizzie from '@/components/icons/IconLizzie.vue'
import FormSagaSelect from '@/components/FormSagaSelect.vue'
import FormRating from '@/components/FormRating.vue'

const props = defineProps<{
  initial?: Book | null
  submitLabel?: string
  error?: string | null
  searchTerm?: string
}>()

const emit = defineEmits<{
  submit: [book: Book]
  cancel: []
}>()

const bookStore = useBookStore()

const books = ref<Array<Book>>([])
const loading = ref(false)
const searchError = ref('')
const term = ref('')
const selectedSource = ref('')
// Le label du champ tags enveloppe aussi les chips (des boutons) : sans
// association explicite, le contrôle du label serait la PREMIÈRE croix
// (premier labelable descendant) — le hover/clic du champ lui serait
// transmis (croix blanchie au survol du cadre, tag retiré au clic).
const tagsInputId = useId()
const scrapper = ref<Book['scraper']>('audible')
const scrapId = ref<Book['scrapId']>('')
const cover = ref('')
const title = ref('')
const author = ref('')
const narrators = ref('')
const tagChips = ref(new Array<string>())
const tagQuery = ref('')
const tagOpen = ref(false)
const tagActive = ref(-1)
const tagsLoading = ref(false)
const runtime = ref('')
const ratings = ref(0)
const saga = ref('')
const tome = ref('')

const virtualBook = computed<Book>(() => {
  const book = new Book()

  book.scraper = scrapper.value
  book.scrapId = scrapId.value

  book.title = title.value
  book.cover = cover.value
  book.author = author.value

  book.narrators = narrators.value.split(/,\s*/g)

  book.tags = [...tagChips.value]

  book.runtime = runtime.value
  book.ratings = ratings.value

  if (saga.value) {
    book.saga = new Saga()
    book.saga.name = saga.value
    book.saga.tome = tome.value
  }

  return book
})

const sourceHref = computed(() => {
  if (scrapper.value === 'audible') {
    return `https://www.audible.fr/pd/Book/${scrapId.value}`
  }

  if (scrapper.value === 'lizzie') {
    return `https://www.lizzie.audio/content/${scrapId.value}`
  }

  return ''
})

const sourceLabel = computed(() => {
  if (scrapper.value === 'audible') {
    return 'Lien vers la fiche Audible'
  }

  if (scrapper.value === 'lizzie') {
    return 'Lien vers la fiche Lizzie'
  }

  return ''
})

// Détecte un livre déjà présent en bibliothèque (même scraper + scrapId),
// hors de celui éventuellement en cours d'édition — signale un doublon.
const duplicate = computed(() => {
  const source = `${scrapper.value}#${scrapId.value}`

  if (!scrapId.value) {
    return
  }

  return bookStore.books.find(
    (book) => book.id !== props.initial?.id && `${book.scraper}#${book.scrapId}` === source,
  )
})

// Suggestions de tags collectées depuis la bibliothèque (aucune gestion de tags
// en base) : hors des chips déjà saisies, filtrés sur le texte tapé — casse et
// accents ignorés, à la manière du sélecteur de saga.
const tagSuggestions = computed(() => {
  const needle = loose(tagQuery.value)

  return collectTags(bookStore.books)
    .map((tag) => tag.name)
    .filter((name) => !tagChips.value.some((chip) => loose(chip) === loose(name)))
    .filter((name) => !needle || loose(name).includes(needle))
})

// Tags déjà présents dans la bibliothèque (clés « loose » : casse et accents
// ignorés, même règle que les suggestions) — une chip hors de cet ensemble est
// un tag nouveau, créé avec le livre.
const libraryTagKeys = computed(() => new Set(collectTags(bookStore.books).map((tag) => loose(tag.name))))

const NEW_TAG_TITLE = 'Nouveau tag'

function isKnownTag(tag: string): boolean {
  return libraryTagKeys.value.has(loose(tag))
}

let lastSearch = ''
let tagsFetchId = 0
let abortController = new AbortController()
let searchHandle = 0

watch(
  () => props.initial,
  (book) => {
    if (book) {
      select(book)
    } else {
      reset()
    }
  },
  { immediate: true },
)

watch(term, onSearchChange)

watch(
  () => props.searchTerm,
  (value) => {
    if (value) {
      term.value = value
    }
  },
  { immediate: true },
)

function select(book: Book, fetchTags = false): void {
  selectedSource.value = `${book.scraper}#${book.scrapId}`
  scrapper.value = book.scraper
  scrapId.value = book.scrapId
  cover.value = book.cover
  title.value = book.title
  author.value = book.author
  narrators.value = book.narrators.join(', ')
  tagChips.value = [...book.tags]
  tagQuery.value = ''
  tagOpen.value = false
  tagActive.value = -1
  runtime.value = book.runtime
  ratings.value = book.ratings ?? 0
  saga.value = book.saga?.name || ''
  tome.value = book.saga?.tome || ''

  // Les tags ne figurent que sur la fiche Audible (ni la recherche, ni la page
  // série ne les exposent) : on va les chercher à la sélection d'une suggestion.
  // Rien à faire pour Lizzie.
  if (fetchTags && book.scraper === 'audible' && book.scrapId) {
    void fetchBookTags(book.scrapId)
  }
}

/**
 * Scrap des tags de la fiche sélectionnée : remplit le champ quand la réponse
 * arrive, sauf si une autre suggestion a été sélectionnée entre-temps (réponse
 * périmée). Un échec laisse le champ tel quel : il reste éditable à la main.
 */
async function fetchBookTags(scrapId: string): Promise<void> {
  // Seul le dernier fetch décroche le spinner (les réponses périmées sont jetées).
  const id = ++tagsFetchId
  tagsLoading.value = true

  try {
    const fetched = await scrapTags('audible', scrapId)

    if (selectedSource.value === `audible#${scrapId}`) {
      tagChips.value = [...fetched]
    }
  } catch {
    // Échec silencieux : la saisie manuelle reste la suite.
  } finally {
    if (id === tagsFetchId) {
      tagsLoading.value = false
    }
  }
}

/**
 * Ajoute un tag (suggestion ou saisie libre) : dédupliqué sans casse ni accents.
 * Un doublon n'est jamais ajouté — la saisie est simplement vidée.
 */
function addTag(raw: string): void {
  const name = raw.trim()

  if (!name) {
    return
  }

  if (!tagChips.value.some((chip) => loose(chip) === loose(name))) {
    tagChips.value = [...tagChips.value, name]
  }

  tagQuery.value = ''
  tagActive.value = -1
}

function removeTag(index: number): void {
  tagChips.value = tagChips.value.filter((_, i) => i !== index)
}

function onTagInput(): void {
  tagOpen.value = true
  tagActive.value = -1
}

function onTagKeydown(event: KeyboardEvent): void {
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()

    if (tagOpen.value && tagSuggestions.value.length) {
      const delta = event.key === 'ArrowDown' ? 1 : -1
      tagActive.value =
        (tagActive.value + delta + tagSuggestions.value.length) % tagSuggestions.value.length
    }

    return
  }

  if (event.key === 'Enter') {
    // Entrée ne doit jamais soumettre le formulaire ici : elle ajoute la
    // suggestion active, ou crée le tag saisi s'il n'y en a pas.
    event.preventDefault()

    const suggestion = tagOpen.value && tagActive.value >= 0 ? tagSuggestions.value[tagActive.value] : undefined

    addTag(suggestion ?? tagQuery.value)
    return
  }

  if (event.key === 'Escape') {
    tagOpen.value = false
    tagActive.value = -1
  }
}

// Fermeture différée : laisser le temps au mousedown.prevent d'ajouter la
// suggestion avant que le dropdown disparaisse (pattern du sélecteur de saga).
function onTagBlur(): void {
  setTimeout(() => {
    tagOpen.value = false
    tagActive.value = -1
  }, 150)
}

function onSearchChange(): void {
  if (term.value === lastSearch) {
    return
  }

  lastSearch = term.value
  clearTimeout(searchHandle)
  searchError.value = ''
  // L'état « Recherche en cours » démarre à la frappe (pas après le debounce) :
  // pendant l'attente, la zone vide n'affiche jamais « Aucun résultat ».
  loading.value = true
  abortController.abort()
  abortController = new AbortController()
  searchHandle = setTimeout(async () => {
    const current = lastSearch

    loading.value = true

    try {
      const results = await search(current, abortController.signal)

      // Une recherche plus récente a été lancée entre-temps : on jette ce résultat.
      if (lastSearch !== current) {
        return
      }

      books.value = results
    } catch (err) {
      if (lastSearch !== current || (err as Error)?.name === 'AbortError') {
        return
      }

      // Le spinner est débloqué et l'erreur affichée : avant, un échec le laissait tourner.
      searchError.value = 'La recherche a échoué. Vérifie la connexion puis réessaie.'
    } finally {
      if (lastSearch === current) {
        loading.value = false
      }
    }
  }, 200)
}

function retrySearch(): void {
  lastSearch = ''
  onSearchChange()
}

function closeSearch(): void {
  books.value = []
  loading.value = false
  searchError.value = ''
  term.value = ''
  lastSearch = ''
}

function submit(event: Event) {
  event.preventDefault()

  if (tagsLoading.value) {
    return
  }

  emit('submit', virtualBook.value)
}

function reset(): void {
  books.value = []
  loading.value = false
  searchError.value = ''
  term.value = ''
  lastSearch = ''
  selectedSource.value = ''
  scrapper.value = 'audible'
  scrapId.value = ''
  cover.value = ''
  title.value = ''
  author.value = ''
  narrators.value = ''
  tagChips.value = []
  tagQuery.value = ''
  tagOpen.value = false
  tagActive.value = -1
  tagsLoading.value = false
  tagsFetchId++
  runtime.value = ''
  ratings.value = 0
  saga.value = ''
  tome.value = ''
}
</script>

<template>
  <form @submit="submit">
    <div class="duplicate" v-if="duplicate">
      ⚠ Ce livre semble déjà présent dans la bibliothèque :
      <b>{{ duplicate.title }}</b>
      ({{ duplicate.author }}). Pense à supprimer le doublon après enregistrement.
    </div>

    <p v-if="error" class="error">{{ error }}</p>

    <div class="body">
      <div class="fields">
        <label>
          <span>Titre</span>
          <input type="text" v-model="title" />
        </label>

        <label>
          <span>Auteur</span>
          <input type="text" v-model="author" />
        </label>

        <label>
          <span>Narrateurs</span>
          <input type="text" v-model="narrators" />
        </label>

        <label>
          <span>Durée</span>
          <input type="text" v-model="runtime" />
        </label>

        <label>
          <span>Note</span>
          <FormRating v-model="ratings" />
        </label>

        <label>
          <span>Saga</span>
          <FormSagaSelect v-model="saga" />
        </label>

        <label v-if="saga">
          <span>Tome</span>
          <input type="text" v-model="tome" />
        </label>

        <label>
          <span>Couverture</span>
          <input type="text" v-model="cover" />
        </label>

        <label :for="tagsInputId">
          <span>Tags</span>
          <div class="tags-box">
            <span
              v-for="(tag, index) of tagChips"
              v-bind:key="tag"
              class="tag"
              :class="{ new: !isKnownTag(tag) }"
              :title="isKnownTag(tag) ? undefined : NEW_TAG_TITLE"
            >
              {{ tag }}
              <button
                type="button"
                class="remove"
                aria-label="Retirer le tag"
                @click="removeTag(index)"
              >
                ×
              </button>
            </span>

            <input
              :id="tagsInputId"
              type="text"
              v-model="tagQuery"
              placeholder="Chercher ou créer un tag..."
              @input="onTagInput"
              @focus="tagOpen = true"
              @blur="onTagBlur"
              @keydown="onTagKeydown"
            />

            <div class="tags-dropdown" v-show="tagOpen && tagSuggestions.length > 0">
              <button
                v-for="(name, index) in tagSuggestions"
                v-bind:key="name"
                type="button"
                :class="{ active: index === tagActive }"
                @mousedown.prevent="addTag(name)"
              >
                {{ name }}
              </button>
            </div>
          </div>
          <IconLoading v-if="tagsLoading" class="spinner" />
        </label>
      </div>

      <div class="preview">
        <BookLarge :book="virtualBook" :no-download="true" :link-tags="false" />
      </div>
    </div>

    <div class="actions">
      <a v-if="scrapId" class="source" :href="sourceHref" target="_blank" rel="noopener noreferrer">
        <IconAudible v-if="scrapper === 'audible'" />
        <IconLizzie v-else />
        {{ sourceLabel }}
      </a>

      <div class="buttons">
        <button type="button" class="ghost" @click="emit('cancel')">
          <IconClose />
          Annuler
        </button>
        <button type="submit" class="solid" :disabled="tagsLoading">
          <IconSave />
          {{ submitLabel ?? 'Enregistrer' }}
        </button>
      </div>
    </div>
  </form>

  <div class="separator" aria-hidden="true"></div>

  <div class="suggestions">
    <div class="suggestions-scroll">
      <div class="search">
        <div class="search-input">
          <input
            type="text"
            v-model="term"
            @input="onSearchChange"
            placeholder="Rechercher des métadonnées..."
          />
          <IconLoading v-if="loading" class="spinner" />
        </div>
        <button type="button" v-if="term" @click.prevent="closeSearch"><IconClose /></button>
      </div>

      <div class="results" v-if="books.length">
        <BookCard
          v-for="(book, index) of books"
          v-bind:key="book.scrapId || index"
          :book="book"
          class="suggestion"
          :class="{ selected: book.scrapId && `${book.scraper}#${book.scrapId}` === selectedSource }"
          @click="select(book, true)"
        />
      </div>

      <div v-else-if="searchError" class="empty">
        {{ searchError }}
        <button type="button" class="neon" @click="retrySearch">Réessaie</button>
      </div>

      <!-- Recherche en vol sans vignette affichée : pas de « Aucun résultat »
           prématuré (si des vignettes sont là, elles restent — branche du haut). -->
      <div v-else-if="loading && term" class="empty">Recherche en cours</div>

      <div v-else-if="term" class="empty">
        Aucun résultat trouvé pour
        <i>{{ term }}</i>
        mais vous pouvez saisir manuellement les métadonnées !
      </div>
    </div>

    <div class="suggestions-shade" aria-hidden="true"></div>
  </div>
</template>

<style scoped>
form {
  display: flex;
  flex-direction: column;
  gap: 1rem;

  .duplicate {
    margin: 0;
    color: var(--color-text);
    background-color: oklch(48% 0.2 25 / 0.35);
    border: 1px solid oklch(63% 0.24 25);
    border-radius: 4px;
    padding: 0.375rem 0.75rem;
  }

  .error {
    margin: 0;
    color: var(--color-text);
    background-color: oklch(48% 0.2 25 / 0.35);
    border: 1px solid oklch(63% 0.24 25);
    border-radius: 4px;
    padding: 0.375rem 0.75rem;
  }

  .body {
    display: flex;
    align-items: flex-start;
    gap: 1.5rem;
  }

  .actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;

    .source,
    button {
      display: flex;
      align-items: center;
      gap: 0.25rem;
      padding: 0.375rem 1rem;
      border: 1px solid var(--color-amber);
      border-radius: 999px;
      cursor: pointer;
      transition: 0.4s;
    }

    .source {
      text-decoration: none;
      background-color: transparent;
      color: var(--color-amber);

      &:hover {
        color: var(--color-amber-lighter);
        box-shadow: 0 0 8px var(--color-amber-light);
      }
    }

    .buttons {
      margin-left: auto;
      display: flex;
      gap: 0.5rem;
    }

    .solid {
      background-color: var(--color-amber);
      border-color: transparent;
      color: var(--color-amber-darker);

      &:hover:not(:disabled) {
        background-color: var(--color-amber-light);
        color: var(--color-amber-darker);
        box-shadow: 0 0 10px var(--color-amber-light);
      }

      &:disabled {
        opacity: 0.5;
        cursor: not-allowed;
      }
    }

    .ghost {
      background-color: transparent;
      color: var(--color-amber);

      &:hover {
        color: var(--color-amber-lighter);
        box-shadow: 0 0 8px var(--color-amber-light);
      }
    }
  }

  .fields {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
    flex: 1 1 auto;
    min-width: 0;
  }

  label {
    display: flex;
    align-items: center;
    gap: 0.75rem;

    > span {
      width: 7rem;
      flex-shrink: 0;
      text-align: right;
      font-size: 0.8rem;
      color: var(--color-amber);
    }

    > input[type='text'] {
      flex: 1;
    }

    > .tags-box {
      flex: 1;
    }

    .spinner {
      flex-shrink: 0;
      width: 1rem;
      height: 1rem;
      color: var(--color-amber);
    }
  }

  .tags-box {
    /* Cadre façon input (bordure ambre, rayon 4, lueur au focus) contenant les
       chips et le champ de saisie libre. */
    position: relative;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.25rem;
    min-width: 0;
    padding: 0.25rem 0.5rem;
    background-color: transparent;
    border: 1px solid var(--color-amber);
    border-radius: 4px;
    color: var(--color-text);
    transition:
      border-color 0.2s,
      box-shadow 0.2s;

    &:hover,
    &:focus-within {
      border-color: var(--color-amber-light);
      box-shadow: 0 0 8px oklch(87.9% 0.169 91.605);
    }

    > input {
      /* Champ interne sans bordure : le cadre est porté par la boîte. */
      flex: 1;
      min-width: 8rem;
      padding: 0.125rem 0.25rem;
      background: none;
      border: none;

      &:hover,
      &:focus {
        border: none;
        box-shadow: none;
      }
    }

    .tag {
      display: inline-flex;
      align-items: center;
      gap: 0.25rem;
      padding: 0.125rem 0.5rem;
      font-size: 0.8rem;
      color: var(--color-amber);
      background-color: color-mix(in oklab, var(--color-amber) 12%, transparent);
      border: 1px solid var(--color-amber);
      border-radius: 999px;

      /* Tag absent de la bibliothèque : bordure pointillée — « brouillon »,
         le tag sera créé avec le livre (info-bulle au survol). */
      &.new {
        border-style: dashed;
      }

      .remove {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        font-size: 0.9rem;
        line-height: 1;
        background: none;
        border: none;
        color: inherit;
        cursor: pointer;

        &:hover {
          color: var(--color-amber-lighter);
        }
      }
    }

    .tags-dropdown {
      position: absolute;
      top: 100%;
      left: 0;
      right: 0;
      margin-top: 2px;
      max-height: 12rem;
      overflow-y: auto;
      background-color: var(--color-background-mute);
      border: 1px solid var(--color-amber);
      border-radius: 4px;
      z-index: 10;
      scrollbar-color: var(--color-amber) transparent;
      scrollbar-width: thin;

      button {
        display: block;
        width: 100%;
        text-align: start;
        padding: 0.375rem 0.75rem;
        background: transparent;
        border: none;
        color: var(--color-text);
        cursor: pointer;
        font-size: inherit;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;

        &:hover,
        &.active {
          background-color: var(--color-amber);
          color: var(--color-amber-darker);
        }
      }
    }
  }

  button.neon {
    border: none;
    background: none;
    display: flex;
    align-items: center;
    flex: none;
    gap: 0.25rem;
    cursor: pointer;
    font-size: 14px;
  }
}

.preview {
  flex: none;
}

.separator {
  flex: none;
  height: 1px;
  width: 100%;
  margin-top: 6px;
  background: linear-gradient(
      to right,
      transparent,
      var(--color-amber) 20%,
      var(--color-amber) 80%,
      transparent
    );
  box-shadow: 0 0 10px color-mix(in oklab, var(--color-amber) 45%, transparent);
}

.suggestions-shade {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 1rem;
  background: linear-gradient(to bottom, rgb(0 0 0 / 0.4), transparent);
  mask-image: linear-gradient(to right, transparent, black 20%, black 80%, transparent);
  pointer-events: none;
}

@media (prefers-color-scheme: dark) {
  .suggestions-shade {
    background:
      linear-gradient(to bottom, black, transparent),
      linear-gradient(to bottom, color-mix(in oklab, var(--color-amber) 14%, transparent), transparent);
  }
}

.suggestions {
  position: relative;
  display: flex;
  flex-direction: column;
  width: 100%;
  min-height: 0;

  .suggestions-scroll {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.75rem;
    flex: 1 1 auto;
    min-height: 0;
    max-height: 60vh;
    overflow-y: auto;
    padding-top: 1rem;
    scrollbar-color: var(--color-amber) transparent;
    scrollbar-width: thin;
  }

  .search {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    width: 100%;
    max-width: 28rem;

    input {
      flex: 1;
    }
  }

  .search-input {
      position: relative;
      flex: 1;

      input {
        width: 100%;
        padding-right: 2rem;
      }

      .spinner {
        position: absolute;
        right: 0.5rem;
        top: 0;
        bottom: 0;
        margin: auto 0;
        color: var(--color-amber);
      }
    }

    button {
      border: none;
      background: none;
      cursor: pointer;
      color: var(--color-amber);
      padding: 0.25rem;
      display: flex;

      &:hover {
        color: var(--color-amber-lighter);
      }
    }

  .results {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(9rem, 100%), 1fr));
    justify-content: center;
    gap: 1rem;
    width: 100%;
  }

  .suggestion {
    cursor: pointer;
    overflow: hidden;

    &:hover {
      box-shadow: 0 0 10px var(--color-amber);
    }

    &.selected {
      position: relative;
      border-color: var(--color-amber-lighter);
      box-shadow: 0 0 12px var(--color-amber-light);

      &::after {
        content: '✓';
        position: absolute;
        top: 0.375rem;
        right: 0.375rem;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 1.25rem;
        height: 1.25rem;
        border-radius: 50%;
        background-color: var(--color-amber);
        color: var(--color-amber-darker);
        font-size: 0.8rem;
        line-height: 1;
      }
    }
  }

  .empty {
    color: var(--color-dark);

    button {
      border: none;
      background: none;
      cursor: pointer;
    }
  }
}

@container content (max-width: 52rem) {
  form {
    width: 100%;
  }

  form .body {
    flex-direction: column;
    align-items: stretch;
  }

  form .preview {
    display: flex;
    justify-content: center;
    width: 100%;
    min-width: 0;
  }

  form .actions {
    flex-wrap: wrap;
  }

  form .fields label {
    flex-wrap: wrap;
  }

  form .fields label > span {
    width: 100%;
    text-align: left;
  }
}
</style>
