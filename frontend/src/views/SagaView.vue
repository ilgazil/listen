<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useBookStore } from '@/stores/book.ts'
import BookLarge from '@/components/BookLarge.vue'
import LinkDownload from '@/components/LinkDownload.vue'
import LinkScrapper from '@/components/LinkScrapper.vue'

const route = useRoute()
const bookStore = useBookStore()

const saga = computed(() => bookStore.sagaById(String(route.params.id)))
const books = computed(() => (saga.value ? bookStore.fromSaga(saga.value) : []))
</script>

<template>
  <div class="saga-view">
    <div class="head">
      <RouterLink to="/" class="neon back">← Retour</RouterLink>

      <h1 v-if="saga" class="title">{{ saga.name }}</h1>
    </div>

    <main>
      <template v-if="saga">
        <BookLarge v-for="book of books" v-bind:key="book.id" :book="book">
          <LinkDownload :book="book" class="action" />
          <LinkScrapper :book="book" class="action" />
        </BookLarge>
      </template>

      <p v-else class="empty">Saga introuvable.</p>
    </main>
  </div>
</template>

<style scoped>
.saga-view {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}

.head {
  display: flex;
  flex: none;
  flex-direction: column;
  align-items: center;
  gap: 0.25rem;
  padding: 1rem 1rem 0;

  .back {
    align-self: flex-start;
  }

  .title {
    color: var(--color-amber);
    text-align: center;
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
  color: var(--color-dark);
}
</style>
