<script setup lang="ts">
import { ref, computed } from 'vue'
import BookLarge from '@/components/BookLarge.vue'
import SagaTile from '@/components/SagaTile.vue'
import { useBookStore } from '@/stores/book.ts'
import { bookMatches, workMatches } from '@/entities/book.ts'
import IconDownload from '@/components/icons/IconDownload.vue'
import LinkDownload from '@/components/LinkDownload.vue'
import LinkScrapper from '@/components/LinkScrapper.vue'

const bookStore = useBookStore()

const mode = ref<'sagas' | 'books'>('sagas')
const query = ref('')

const filteredWorks = computed(() =>
  bookStore.works.filter((work) => workMatches(work, query.value)),
)
const filteredBooks = computed(() =>
  bookStore.orderedBooks.filter((book) => bookMatches(book, query.value)),
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
        <input
          v-model="query"
          type="search"
          placeholder="Chercher par titre, saga, auteur ou narrateur"
          class="search"
          :class="{ 'is-empty': !query }"
        />

        <button
          v-if="query"
          type="button"
          class="clear"
          aria-label="Effacer la recherche"
          @click="query = ''"
        >
          ×
        </button>
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
        v-if="query && (mode === 'sagas' ? filteredWorks.length === 0 : filteredBooks.length === 0)"
        class="empty"
      >
        Aucun résultat
      </p>
    </main>
  </div>

  <RouterLink to="/add" class="neon add">
    <IconDownload />
  </RouterLink>
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
  align-items: center;
  justify-content: center;
  gap: 1rem;
  padding: 0.5rem 1rem;
}

.toggle {
  display: flex;

  button {
    padding: 0.125rem 0.75rem;
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
}

.search {
  width: 19rem;
  padding: 0.25rem 1.5rem 0.25rem 0.75rem;
  font-size: 0.8rem;
  background-color: transparent;
  border: 1px solid var(--color-amber);
  border-radius: 999px;
  color: var(--color-amber);
  transition: 0.4s;

  &.is-empty {
    opacity: 0.6;
  }

  &:hover,
  &:focus {
    opacity: 1;
  }

  &::placeholder {
    color: var(--color-amber);
  }

  &::-webkit-search-cancel-button {
    appearance: none;
    -webkit-appearance: none;
  }

  &:hover {
    box-shadow: 0 0 8px var(--color-amber-light);
  }

  &:focus {
    outline: none;
    border-color: var(--color-amber);
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

.add {
  position: fixed;
  display: flex;
  align-items: center;
  justify-content: center;
  bottom: 1rem;
  right: 1rem;
  width: 4rem;
  height: 4rem;
  border-radius: 50%;
  background-color: var(--color-amber);
  color: var(--color-amber-darker);

  &:hover {
    background-color: var(--color-amber-light);
    color: var(--color-amber-darker);
    box-shadow: 0 0 12px var(--color-amber-light);
  }

  > * {
    width: 3.5rem;
    height: 3.5rem;
  }
}
</style>
