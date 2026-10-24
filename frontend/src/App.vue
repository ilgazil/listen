<script setup lang="ts">
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { onMounted } from 'vue'
import { fetchBooks } from '@/api/book.ts'
import { useBookStore } from '@/stores/book.ts'
import { useQueueStore } from '@/stores/queue.ts'
import { useHistoryStore } from '@/stores/history.ts'
import FormQueue from '@/components/queue/FormQueue.vue'
import IconDownload from '@/components/icons/IconDownload.vue'

const route = useRoute()
const queueStore = useQueueStore()
const historyStore = useHistoryStore()

onMounted(async () => {
  useBookStore().$patch({ books: await fetchBooks() })
})
</script>

<template>
  <header>
    <!-- Retour dans l'historique (état restauré), accueil en accès direct. Masqué sur l'accueil, qui est la racine. -->
    <button
      v-if="route.path !== '/'"
      type="button"
      class="neon back"
      @click="historyStore.backOrHome()"
    >
      ← Retour
    </button>

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

  <!-- Accès global à l'ajout d'un livre : sur toutes les routes sauf /add (le
       formulaire y est déjà). Masqué quand la file d'envoi est visible : le
       panneau propose alors « Ajouter » dans sa barre d'actions (sinon il
       passerait par dessus). -->
  <RouterLink v-if="route.path !== '/add' && !queueStore.uploads.length" to="/add" class="neon add">
    <IconDownload />
  </RouterLink>
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

  .back {
    margin-right: auto;
    font: inherit;
    background-color: transparent;
    border: none;
    cursor: pointer;
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
