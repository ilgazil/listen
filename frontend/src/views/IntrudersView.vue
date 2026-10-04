<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { deleteIntruder, fetchIntruders } from '@/api/intruders.ts'
import type { Intruder } from '@/api/intruders.ts'
import IconTrash from '@/components/icons/IconTrash.vue'

const intruders = ref<Array<Intruder>>([])
const loading = ref(true)
const error = ref('')
const busyId = ref('')

onMounted(load)

async function load() {
  loading.value = true
  error.value = ''

  try {
    intruders.value = await fetchIntruders()
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Impossible de lister les intrus.'
  } finally {
    loading.value = false
  }
}

async function remove(intruder: Intruder) {
  if (
    !window.confirm(
      `Supprimer l’entrée « ${intruder.title} » de la bibliothèque ?\n` +
        'Le fichier est déjà absent de 1Fichier.',
    )
  ) {
    return
  }

  error.value = ''
  busyId.value = intruder.id

  try {
    await deleteIntruder(intruder.id)
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'La suppression a échoué.'
  } finally {
    busyId.value = ''
    await load()
  }
}
</script>

<template>
  <div class="intruders">
    <p v-if="error" class="error">{{ error }}</p>
    <p v-else-if="loading" class="empty">Recherche des intrus…</p>
    <p v-else-if="intruders.length === 0" class="empty">Aucun intrus dans la bibliothèque.</p>

    <main v-else>
      <article v-for="intruder of intruders" v-bind:key="intruder.id" class="book-card">
        <div class="book-info">
          <h2 class="truncate" :title="intruder.title">{{ intruder.title }}</h2>

          <p v-if="intruder.author" class="truncate">{{ intruder.author }}</p>

          <p v-if="intruder.saga" class="saga truncate">
            {{ intruder.saga.name }}
            <span v-if="intruder.saga.tome">· tome {{ intruder.saga.tome }}</span>
          </p>

          <p class="id">{{ intruder.id }}</p>
        </div>

        <button
          type="button"
          class="delete"
          :disabled="busyId !== ''"
          :title="`Supprimer « ${intruder.title} » de la bibliothèque`"
          @click="remove(intruder)"
        >
          <IconTrash />
          Supprimer
        </button>
      </article>
    </main>
  </div>
</template>

<style scoped>
.intruders {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}

.error,
.empty {
  margin: auto;
  color: var(--color-dark);
  text-align: center;
}

.error {
  color: var(--color-amber);
}

main {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  gap: 0.5rem;
  padding: 1rem;
  overflow: auto;
}

.book-card {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.75rem 1rem;
  background-color: transparent;
  border: 1px solid var(--color-amber);
  border-radius: 6px;
  transition: 0.4s;

  &:hover {
    border-color: var(--color-amber-light);
    box-shadow: 0 0 8px var(--color-amber-light);
  }
}

.book-info {
  flex: 1 1 auto;
  min-width: 0;

  h2 {
    margin: 0;
    font-size: 1rem;
    font-weight: normal;
    color: var(--color-amber);
  }

  p {
    margin: 0.15rem 0 0;
    font-size: 0.8rem;
    color: var(--color-dark);
  }

  .saga {
    color: var(--color-text);
  }

  .id {
    font-family: monospace;
    font-size: 0.7rem;
  }
}

.delete {
  display: flex;
  flex: none;
  align-items: center;
  gap: 0.25rem;
  padding: 0.25rem 0.75rem;
  border: 1px solid var(--color-amber);
  border-radius: 999px;
  background-color: transparent;
  color: var(--color-amber);
  cursor: pointer;
  transition: 0.4s;

  &:hover:not(:disabled) {
    color: oklch(70% 0.18 25);
    border-color: oklch(70% 0.18 25);
    box-shadow: 0 0 8px oklch(70% 0.18 25 / 0.5);
  }

  &:disabled {
    opacity: 0.5;
    cursor: wait;
  }
}
</style>