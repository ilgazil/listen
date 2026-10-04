<script setup lang="ts">
import { computed, ref, useTemplateRef, watch } from 'vue'
import { search } from '@/api/book.ts'
import { Book } from '@/entities/book.ts'
import { Saga } from '@/entities/saga.ts'
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
const term = ref('')
const selectedSource = ref('')
const scrapper = ref<Book['scraper']>('audible')
const scrapId = ref<Book['scrapId']>('')
const cover = ref('')
const title = ref('')
const author = ref('')
const narrators = ref('')
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

let lastSearch = ''
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

function select(book: Book): void {
  selectedSource.value = `${book.scraper}#${book.scrapId}`
  scrapper.value = book.scraper
  scrapId.value = book.scrapId
  cover.value = book.cover
  title.value = book.title
  author.value = book.author
  narrators.value = book.narrators.join(', ')
  runtime.value = book.runtime
  ratings.value = book.ratings ?? 0
  saga.value = book.saga?.name || ''
  tome.value = book.saga?.tome || ''
}

const searchInput = useTemplateRef<HTMLInputElement>('search-input')

function focusSearch() {
  searchInput.value?.focus()
}

function onSearchChange(): void {
  if (term.value === lastSearch) {
    return
  }

  lastSearch = term.value
  clearTimeout(searchHandle)
  abortController.abort()
  abortController = new AbortController()
  searchHandle = setTimeout(async () => {
    loading.value = true
    books.value = await search(lastSearch)
    loading.value = false
  }, 200)
}

function closeSearch(): void {
  books.value = []
  loading.value = false
  term.value = ''
  lastSearch = ''
}

function submit(event: Event) {
  event.preventDefault()

  emit('submit', virtualBook.value)
}

function reset(): void {
  books.value = []
  loading.value = false
  term.value = ''
  lastSearch = ''
  selectedSource.value = ''
  scrapper.value = 'audible'
  scrapId.value = ''
  cover.value = ''
  title.value = ''
  author.value = ''
  narrators.value = ''
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
      </div>

      <div class="preview">
        <BookLarge :book="virtualBook" :no-download="true" />
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
        <button type="submit" class="solid">
          <IconSave />
          {{ submitLabel ?? 'Enregistrer' }}
        </button>
      </div>
    </div>
  </form>

  <div class="suggestions">
    <div class="search">
      <div class="search-input">
        <input
          type="text"
          ref="search-input"
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
        @click="select(book)"
      />
    </div>

    <div v-else-if="term" class="empty">
      Aucun résultat trouvé pour
      <i>{{ term }}</i
      >.
      <button type="button" class="neon" @click="focusSearch">Affinez-le</button>
      ou saisissez manuellement les métadonnées !
    </div>
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
      display: flex;
      gap: 0.5rem;
    }

    .solid {
      background-color: var(--color-amber);
      border-color: transparent;
      color: var(--color-amber-darker);

      &:hover {
        background-color: var(--color-amber-light);
        color: var(--color-amber-darker);
        box-shadow: 0 0 10px var(--color-amber-light);
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

.suggestions {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
  width: 100%;

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
