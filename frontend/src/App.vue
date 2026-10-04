<script setup lang="ts">
import { RouterLink, RouterView } from 'vue-router'
import { onMounted } from 'vue'
import { fetchBooks } from '@/api/book.ts'
import { useBookStore } from '@/stores/book.ts'
import { useQueueStore } from '@/stores/queue.ts'
import FormQueue from '@/components/queue/FormQueue.vue'

const queueStore = useQueueStore()

onMounted(async () => {
  useBookStore().$patch({ books: await fetchBooks() })
})
</script>

<template>
  <header>
    <RouterLink to="/" class="neon">Hello my friend, stay awhile and listen.</RouterLink>
  </header>

  <main>
    <div class="content">
      <RouterView />
    </div>

    <div class="queue" v-if="queueStore.uploads.length">
      <FormQueue />
    </div>
  </main>
</template>

<style scoped>
header {
  display: flex;
  flex: none;
  align-items: flex-end;
  justify-content: flex-end;
  height: 10vh;
  padding: 1rem;
  background-image: url('@/assets/deckard-cain.jpg');
  background-repeat: no-repeat;
  background-size: cover;
  background-position: center;

  + * {
    flex-grow: 1;
  }
}

main {
  display: flex;
  overflow: hidden;
  height: 100%;

  .content {
    flex-grow: 1;
    overflow: auto;
    scrollbar-color: var(--color-amber) transparent;
    scrollbar-width: thin;
    container-type: inline-size;
    container-name: content;
  }

  .queue {
    display: flex;
    flex: none;
    overflow: hidden;
    width: 20rem;
    background-color: oklch(41.4% 0.112 45.904);
  }

  /* En dessous de 1024px : la file passe sous le formulaire pour le laisser utilisable. */
  @media (max-width: 1023.98px) {
    flex-direction: column;

    .queue {
      width: 100%;
      height: 33vh;
    }
  }
}
</style>
