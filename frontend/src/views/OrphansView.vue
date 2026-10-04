<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { fetchBooks } from '@/api/book.ts'
import { deleteOrphan, fetchOrphans, fixOrphan } from '@/api/orphans.ts'
import type { Orphan } from '@/api/orphans.ts'
import { Book } from '@/entities/book.ts'
import { useBookStore } from '@/stores/book.ts'
import BookForm from '@/components/BookForm.vue'
import IconTrash from '@/components/icons/IconTrash.vue'
import IconClose from '@/components/icons/IconClose.vue'

const bookStore = useBookStore()

const orphans = ref<Array<Orphan>>([])
const selected = ref<Orphan>()
const loading = ref(true)
const error = ref('')

const formError = ref('')

// Comme en /add : le nom du fichier orphelin pré-remplit la recherche de métadonnées.
const searchTerm = computed(() => {
  const name = selected.value?.name

  if (!name) {
    return ''
  }

  const lastDot = name.lastIndexOf('.')

  return lastDot > 0 ? name.substring(0, lastDot) : name
})

const selectedIndex = computed(() =>
  orphans.value.findIndex((orphan) => orphan.id === selected.value?.id),
)
const previousId = computed(() => {
  const index = selectedIndex.value
  const previous = orphans.value[index - 1]

  return previous?.id
})
const nextId = computed(() => {
  const index = selectedIndex.value
  const next = orphans.value[index + 1]

  return next?.id
})

onMounted(load)

async function load() {
  loading.value = true
  error.value = ''

  try {
    const [newOrphans, books] = await Promise.all([fetchOrphans(), fetchBooks()])
    orphans.value = newOrphans
    bookStore.$patch({ books })
  } catch (err) {
    error.value =
      err instanceof Error ? err.message : 'Impossible de lister les fichiers orphelins.'
  } finally {
    loading.value = false
  }
}

async function submit(book: Book) {
  if (!selected.value) {
    return
  }

  formError.value = ''

  try {
    await fixOrphan(selected.value.id, book)
    bookStore.$patch({ books: await fetchBooks() })
    await load()
    selected.value = undefined
  } catch (err) {
    formError.value = err instanceof Error ? err.message : 'L’enregistrement a échoué.'
  }
}

async function remove() {
  if (!selected.value) {
    return
  }

  if (
    !window.confirm(`Supprimer définitivement le fichier « ${selected.value.name} » de 1Fichier ?`)
  ) {
    return
  }

  formError.value = ''

  try {
    await deleteOrphan(selected.value.id)
    if (nextId.value) {
      selected.value = orphans.value.find((orphan) => orphan.id === nextId.value)
    } else if (previousId.value) {
      selected.value = orphans.value.find((orphan) => orphan.id === previousId.value)
    } else {
      selected.value = undefined
    }
    await load()
  } catch (err) {
    formError.value = err instanceof Error ? err.message : 'La suppression a échoué.'
  }
}

function cancel() {
  selected.value = undefined
  formError.value = ''
}
</script>

<template>
  <div class="orphans">
    <p v-if="error" class="error">{{ error }}</p>
    <p v-else-if="loading" class="empty">Recherche des fichiers orphelins…</p>
    <p v-else-if="orphans.length === 0" class="empty">Aucun fichier orphelin sur 1Fichier.</p>

    <main v-else>
      <template v-if="selected">
        <section class="panel">
          <header class="panel-header">
            <h2>Nouveau livre</h2>

            <p class="file">
              <b>{{ selected.name }}</b>
              <small>Enregistrer ne renomme pas le fichier sur 1Fichier.</small>
            </p>

            <button type="button" class="delete" @click="remove">
              <IconTrash />
              Supprimer
            </button>

            <button type="button" class="panel-close" @click="cancel">
              <IconClose />
            </button>
          </header>

          <BookForm
            :key="selected.id"
            :initial="null"
            :search-term="searchTerm"
            :error="formError"
            @submit="submit"
            @cancel="cancel"
          />
        </section>
      </template>

      <template v-else>
        <button
          v-for="orphan of orphans"
          v-bind:key="orphan.id"
          type="button"
          class="file-card"
          @click="selected = orphan"
        >
          <span class="file-name">{{ orphan.name }}</span>
          <span class="file-size">({{ (orphan.size / 1024 / 1024).toFixed(1) }} Mo)</span>
        </button>
      </template>
    </main>
  </div>
</template>

<style scoped>
.orphans {
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
  flex-wrap: wrap;
  align-content: flex-start;
  justify-content: center;
  gap: 1rem;
  padding: 1rem;
  overflow: auto;
}

.panel {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  width: 100%;
  max-width: 64rem;
}

.panel-header {
  display: flex;
  align-items: center;
  gap: 1rem;

  h2 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: normal;
    color: var(--color-amber);
  }

  .file {
    margin: 0;
    color: var(--color-text);

    small {
      display: block;
      color: var(--color-dark);
    }
  }

  .panel-close {
    margin-left: auto;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0.25rem;
    border: none;
    background: none;
    color: var(--color-amber);
    cursor: pointer;

    &:hover {
      color: var(--color-amber-lighter);
    }
  }

  .delete {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.75rem;
    border: 1px solid var(--color-amber);
    border-radius: 999px;
    background-color: transparent;
    color: var(--color-amber);
    cursor: pointer;
    transition: 0.4s;

    &:hover {
      color: oklch(70% 0.18 25);
      border-color: oklch(70% 0.18 25);
      box-shadow: 0 0 8px oklch(70% 0.18 25 / 0.5);
    }
  }
}

.file-card {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  padding: 0.75rem 1rem;
  background-color: transparent;
  border: 1px solid var(--color-amber);
  border-radius: 6px;
  cursor: pointer;
  transition: 0.4s;

  &:hover {
    border-color: var(--color-amber-light);
    box-shadow: 0 0 8px var(--color-amber-light);
  }

  .file-name {
    color: var(--color-amber);
    font-size: 0.9rem;
  }

  .file-size {
    font-size: 0.75rem;
    color: var(--color-dark);
  }
}
</style>
