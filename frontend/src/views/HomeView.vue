<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BookLarge from '@/components/BookLarge.vue'
import SagaTile from '@/components/SagaTile.vue'
import { useBookStore } from '@/stores/book.ts'
import { bookMatches, workMatches } from '@/entities/book.ts'
import { filterByTag, workHasTag } from '@/helpers/tags.ts'
import { searchSuggestions, type SagaSuggestion } from '@/helpers/suggestions.ts'
import LinkDownload from '@/components/LinkDownload.vue'
import LinkScrapper from '@/components/LinkScrapper.vue'

const route = useRoute()
const router = useRouter()
const bookStore = useBookStore()

// Normalise une valeur de query string (string | string[] | null → string).
function normalize(value: unknown): string {
  const entry = Array.isArray(value) ? value[0] : value

  return typeof entry === 'string' ? entry : ''
}

// Recherche, mode et tag vivent dans l'URL : retour, liens et rechargement les
// restaurent.
const mode = ref<'sagas' | 'books'>(normalize(route.query.mode) === 'books' ? 'books' : 'sagas')
const query = ref(normalize(route.query.q))
const activeTag = ref(normalize(route.query.tag))

// État local → URL (replace : la saisie ne pollue pas l'historique).
watch([query, mode, activeTag], ([nextQuery, nextMode, nextTag]) => {
  const next: { q?: string; mode?: string; tag?: string } = {}

  if (nextQuery) {
    next.q = nextQuery
  }

  if (nextMode === 'books') {
    next.mode = nextMode
  }

  if (nextTag) {
    next.tag = nextTag
  }

  if (
    normalize(route.query.q) === (next.q ?? '') &&
    normalize(route.query.mode) === (next.mode ?? '') &&
    normalize(route.query.tag) === (next.tag ?? '')
  ) {
    return
  }

  void router.replace({ query: next })
})

// URL → état local (boutons Précédent / Suivant, liens).
watch(
  () => route.query,
  (value) => {
    const q = normalize(value.q)
    const m = normalize(value.mode) === 'books' ? 'books' : 'sagas'
    const t = normalize(value.tag)

    if (q !== query.value) {
      query.value = q
    }

    if (m !== mode.value) {
      mode.value = m
    }

    if (t !== activeTag.value) {
      activeTag.value = t
      // L'input dédié reflète le tag appliqué (retour arrière, liens…).
      tagQuery.value = t
    }
  },
)

// — Recherche texte : filtre en direct + suggestions (titres/sagas, auteurs,
// narrateurs — les tags ont leur input dédié).

const searchOpen = ref(false)
const searchActive = ref(-1)

const suggestions = computed(() => searchSuggestions(bookStore.books, query.value))

const suggestionGroups = computed(() => [
  {
    label: 'Titres',
    options: suggestions.value.titles.map((title) => ({
      value: title,
      apply: () => {
        query.value = title
      },
    })),
  },
  {
    label: 'Sagas',
    options: suggestions.value.sagas.map((saga) => ({
      value: saga.name,
      apply: () => {
        void openSaga(saga)
      },
    })),
  },
  {
    label: 'Auteurs',
    options: suggestions.value.authors.map((author) => ({
      value: author,
      apply: () => {
        query.value = author
      },
    })),
  },
  {
    label: 'Narrateurs',
    options: suggestions.value.narrators.map((narrator) => ({
      value: narrator,
      apply: () => {
        query.value = narrator
      },
    })),
  },
])

const flatSuggestions = computed(() =>
  suggestionGroups.value.flatMap((group) =>
    group.options.map((option) => ({
      label: group.label,
      value: option.value,
      apply: option.apply,
    })),
  ),
)

function closeSearchMenu(): void {
  searchOpen.value = false
  searchActive.value = -1
}

function onSearchInput(): void {
  searchOpen.value = true
  searchActive.value = -1
}

function onSearchFocus(): void {
  searchOpen.value = true
}

// Fermeture différée : laisser le temps au mousedown.prevent d'appliquer la
// suggestion avant que le menu disparaisse (pattern du sélecteur de saga).
function onSearchBlur(): void {
  setTimeout(() => closeSearchMenu(), 150)
}

function onSearchKeydown(event: KeyboardEvent): void {
  const count = flatSuggestions.value.length

  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()

    if (searchOpen.value && count) {
      const delta = event.key === 'ArrowDown' ? 1 : -1
      searchActive.value = (searchActive.value + delta + count) % count
    }

    return
  }

  if (event.key === 'Enter' && searchOpen.value && searchActive.value >= 0) {
    event.preventDefault()
    flatSuggestions.value[searchActive.value]?.apply()
    return
  }

  if (event.key === 'Escape') {
    closeSearchMenu()
  }
}

// Ouvre la page d'une saga suggérée : la recherche est réinitialisée (l'entrée
// accueil de l'historique est réécrite sans `q`) mais le filtre de tag est
// conservé — au retour, le champ est vide et la chip toujours en place.
async function openSaga(saga: SagaSuggestion): Promise<void> {
  closeSearchMenu()

  const next: { mode?: string; tag?: string } = {}
  if (mode.value === 'books') {
    next.mode = 'books'
  }
  if (activeTag.value) {
    next.tag = activeTag.value
  }

  // Réécrit d'abord l'entrée accueil (sans `q`), puis ouvre la saga : les deux
  // navigations sont strictement séquentielles, aucune écriture concurrente.
  await router.replace({ query: next })
  await router.push({ name: 'saga', params: { id: saga.id } })
}

// — Filtre par tag : input dédié. La saisie ne filtre PAS — seule la
// validation (Entrée ou choix d'une suggestion) applique le filtre.

const tagQuery = ref('')
const tagOpen = ref(false)
const tagActive = ref(-1)
const tagInput = ref<HTMLInputElement | null>(null)

const tagSuggestions = computed(() => searchSuggestions(bookStore.books, tagQuery.value).tags)

function closeTagMenu(): void {
  tagOpen.value = false
  tagActive.value = -1
}

function onTagInput(): void {
  tagOpen.value = true
  tagActive.value = -1
}

function onTagFocus(): void {
  tagOpen.value = true
}

function onTagBlur(): void {
  setTimeout(() => closeTagMenu(), 150)
}

// Validation : applique le texte saisi tel quel (le filtrage des tags est une
// égalité sans casse ni accents — vide = retire le filtre).
function validateTag(): void {
  activeTag.value = tagQuery.value.trim()
  closeTagMenu()
}

function pickTagSuggestion(name: string): void {
  tagQuery.value = name
  activeTag.value = name
  closeTagMenu()
}

function clearTagFilter(): void {
  tagQuery.value = ''
  activeTag.value = ''
  closeTagMenu()
  // L'input revient : on y remet le focus pour enchaîner un autre tag.
  void nextTick(() => tagInput.value?.focus())
}

function onTagKeydown(event: KeyboardEvent): void {
  const count = tagSuggestions.value.length

  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()

    if (tagOpen.value && count) {
      const delta = event.key === 'ArrowDown' ? 1 : -1
      tagActive.value = (tagActive.value + delta + count) % count
    }

    return
  }

  if (event.key === 'Enter') {
    event.preventDefault()

    const suggestion = tagOpen.value && tagActive.value >= 0 ? tagSuggestions.value[tagActive.value] : undefined

    if (suggestion !== undefined) {
      pickTagSuggestion(suggestion)
    } else {
      validateTag()
    }

    return
  }

  if (event.key === 'Escape') {
    closeTagMenu()
  }
}

// Filtres : le texte (titre/saga/auteur/narrateur) compose avec le tag. Pour
// une saga, un seul livre taggé suffit à l'afficher — tous ses livres suivent.
const filteredWorks = computed(() =>
  bookStore.works.filter(
    (work) =>
      workMatches(work, query.value) && (!activeTag.value || workHasTag(work, activeTag.value)),
  ),
)
const filteredBooks = computed(() =>
  filterByTag(
    bookStore.orderedBooks.filter((book) => bookMatches(book, query.value)),
    activeTag.value,
  ),
)
</script>

<template>
  <div class="home">
    <div class="toolbar">
      <div class="toggle">
        <button type="button" :class="mode === 'sagas' ? 'active' : ''" @click="mode = 'sagas'">
          Sagas
        </button>
        <button type="button" :class="mode === 'books' ? 'active' : ''" @click="mode = 'books'">
          Livres
        </button>
      </div>

      <div class="search-wrap">
        <div class="search" :class="{ 'is-empty': !query }">
          <input
            v-model="query"
            type="search"
            placeholder="Chercher par titre, saga, auteur ou narrateur"
            @input="onSearchInput"
            @focus="onSearchFocus"
            @blur="onSearchBlur"
            @keydown="onSearchKeydown"
          />

          <div class="search-dropdown" v-show="searchOpen && flatSuggestions.length > 0">
            <template v-for="(sug, index) in flatSuggestions" v-bind:key="sug.label + sug.value">
              <div
                v-if="index === 0 || flatSuggestions[index - 1].label !== sug.label"
                class="group-label"
              >
                {{ sug.label }}
              </div>
              <button type="button" :class="{ active: searchActive === index }" @mousedown.prevent="sug.apply()">
                {{ sug.value }}
              </button>
            </template>
          </div>
        </div>

        <button v-if="query" type="button" class="clear" aria-label="Effacer la recherche" @click="query = ''">
          ×
        </button>
      </div>

      <div class="tag-wrap">
        <span v-if="activeTag" class="tag-chip">
          <span class="name">{{ activeTag }}</span>
          <button
            type="button"
            class="remove"
            aria-label="Retirer le filtre de tag"
            @click="clearTagFilter"
          >
            ×
          </button>
        </span>

        <div v-else class="tag-filter" :class="{ 'is-empty': !tagQuery }">
          <input
            ref="tagInput"
            v-model="tagQuery"
            type="search"
            placeholder="Filtrer par tag…"
            @input="onTagInput"
            @focus="onTagFocus"
            @blur="onTagBlur"
            @keydown="onTagKeydown"
          />

          <div class="tag-dropdown" v-show="tagOpen && tagSuggestions.length > 0">
            <button
              v-for="name in tagSuggestions"
              v-bind:key="name"
              type="button"
              :class="{ active: tagSuggestions[tagActive] === name }"
              @mousedown.prevent="pickTagSuggestion(name)"
            >
              {{ name }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <main>
      <template v-if="mode === 'sagas'">
        <template
          v-for="work of filteredWorks"
          v-bind:key="work.type === 'saga' ? work.saga.id : work.book.id"
        >
          <SagaTile v-if="work.type === 'saga'" :saga="work.saga" :books="work.books" />
          <BookLarge v-else :book="work.book">
            <LinkDownload :book="work.book" class="action" />
            <LinkScrapper :book="work.book" class="action" />
          </BookLarge>
        </template>
      </template>

      <template v-else>
        <BookLarge v-for="book of filteredBooks" v-bind:key="book.id" :book="book">
          <LinkDownload :book="book" class="action" />
          <LinkScrapper :book="book" class="action" />
        </BookLarge>
      </template>

      <p
        v-if="
          (query || activeTag) &&
          (mode === 'sagas' ? filteredWorks.length === 0 : filteredBooks.length === 0)
        "
        class="empty"
      >
        Aucun résultat
      </p>
    </main>
  </div>
</template>

<style scoped>
.home {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}

.toolbar {
  display: flex;
  flex: none;
  flex-wrap: wrap;
  align-items: stretch;
  justify-content: center;
  gap: 1rem;
  padding: 0.5rem 1rem;
}

.toggle {
  display: flex;

  button {
    display: flex;
    align-items: center;
    padding: 0 0.75rem;
    font-size: 0.8rem;
    background-color: transparent;
    border: 1px solid var(--color-amber);
    color: var(--color-amber);
    cursor: pointer;
    transition: 0.4s;

    &:first-child {
      border-radius: 999px 0 0 999px;
    }

    &:last-child {
      border-radius: 0 999px 999px 0;
    }

    & + button {
      border-left: none;
    }

    &.active,
    &:hover {
      background-color: var(--color-amber);
      color: var(--color-amber-darker);
    }
  }
}

.search-wrap {
  position: relative;
  display: flex;
}

.search {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  position: relative;
  flex: 1 1 auto;
  min-width: 19rem;
  max-width: 30rem;
  padding: 0.25rem 1.5rem 0.25rem 0.75rem;
  font-size: 0.8rem;
  background-color: transparent;
  border: 1px solid var(--color-amber);
  border-radius: 4px;
  color: var(--color-amber);
  transition: 0.4s;

  &.is-empty {
    opacity: 0.6;
  }

  &:hover,
  &:focus-within {
    opacity: 1;
    border-color: var(--color-amber);
    box-shadow: 0 0 8px var(--color-amber-light);
  }

  input {
    appearance: none;
    -webkit-appearance: none;
    flex: 1 1 auto;
    min-width: 0;
    padding: 0;
    background: none;
    border: none;
    color: var(--color-amber);
    font-size: 0.8rem;
    outline: none;

    &::placeholder {
      color: var(--color-amber);
    }

    &::-webkit-search-cancel-button {
      appearance: none;
      -webkit-appearance: none;
    }
  }

  .search-dropdown {
    position: absolute;
    top: calc(100% + 2px);
    left: 0;
    right: 0;
    max-height: 16rem;
    overflow-y: auto;
    background-color: var(--color-background-mute);
    border: 1px solid var(--color-amber);
    border-radius: 4px;
    z-index: 20;
    scrollbar-color: var(--color-amber) transparent;
    scrollbar-width: thin;

    .group-label {
      padding: 0.25rem 0.75rem 0.125rem;
      font-size: 0.7rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--color-dark);
    }

    button {
      display: block;
      width: 100%;
      text-align: start;
      padding: 0.375rem 0.75rem;
      background: transparent;
      border: none;
      color: var(--color-text);
      cursor: pointer;
      font-size: 0.8rem;
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

.clear {
  position: absolute;
  top: 50%;
  right: 0.4rem;
  transform: translateY(-50%);
  display: flex;
  align-items: center;
  justify-content: center;
  width: 1.1rem;
  height: 1.1rem;
  padding: 0;
  font-size: 1rem;
  line-height: 1;
  background-color: transparent;
  border: none;
  border-radius: 50%;
  color: var(--color-amber);
  cursor: pointer;
  transition: 0.4s;

  &:hover {
    color: var(--color-amber-lighter);
  }
}

.tag-wrap {
  position: relative;
  display: flex;
}

.tag-filter {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  position: relative;
  flex: 1 1 auto;
  min-width: 12rem;
  max-width: 16rem;
  padding: 0.25rem 0.5rem;
  font-size: 0.8rem;
  background-color: transparent;
  border: 1px solid var(--color-amber);
  border-radius: 4px;
  color: var(--color-amber);
  transition: 0.4s;

  &.is-empty {
    opacity: 0.6;
  }

  &:hover,
  &:focus-within {
    opacity: 1;
    border-color: var(--color-amber);
    box-shadow: 0 0 8px var(--color-amber-light);
  }

  input {
    appearance: none;
    -webkit-appearance: none;
    flex: 1 1 auto;
    min-width: 0;
    padding: 0;
    background: none;
    border: none;
    color: var(--color-amber);
    font-size: 0.8rem;
    outline: none;

    &::placeholder {
      color: var(--color-amber);
    }

    &::-webkit-search-cancel-button {
      appearance: none;
      -webkit-appearance: none;
    }
  }

  .tag-dropdown {
    position: absolute;
    top: calc(100% + 2px);
    left: 0;
    right: 0;
    max-height: 16rem;
    overflow-y: auto;
    background-color: var(--color-background-mute);
    border: 1px solid var(--color-amber);
    border-radius: 4px;
    z-index: 20;
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
      font-size: 0.8rem;
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

.tag-chip {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  flex: none;
  max-width: 16rem;
  padding: 0 0.4rem 0 0.75rem;
  font-size: 0.8rem;
  color: var(--color-amber);
  background-color: color-mix(in oklab, var(--color-amber) 12%, transparent);
  border: 1px solid var(--color-amber);
  border-radius: 999px;
  white-space: nowrap;
  transition: 0.4s;

  .name {
    overflow: hidden;
    min-width: 0;
    text-overflow: ellipsis;
  }

  .remove {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: none;
    width: 1.1rem;
    height: 1.1rem;
    padding: 0;
    font-size: 1rem;
    line-height: 1;
    background: none;
    border: none;
    border-radius: 50%;
    color: var(--color-amber);
    cursor: pointer;
    transition: 0.4s;

    &:hover {
      color: var(--color-amber-lighter);
    }
  }
}

main {
  display: flex;
  flex: 1 1 auto;
  flex-wrap: wrap;
  align-content: flex-start;
  justify-content: center;
  gap: 1rem;
  padding: 1rem;
  overflow: auto;
}

.empty {
  width: 100%;
  text-align: center;
  color: var(--color-dark);
}

</style>
