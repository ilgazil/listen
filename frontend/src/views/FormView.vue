<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { updateBook, fetchBooks } from '@/api/book.ts'
import { Book } from '@/entities/book.ts'
import { useQueueStore } from '@/stores/queue.ts'
import { useBookStore } from '@/stores/book.ts'
import BookForm from '@/components/BookForm.vue'
import DropBox from '@/components/DropBox.vue'

const props = defineProps({
  id: String,
})

const router = useRouter()
const bookStore = useBookStore()

const isEditing = computed(() => !!props.id)
const editingBook = computed(() => bookStore.books.find((book) => book.id === props.id))
const notFound = computed(() => isEditing.value && bookStore.books.length > 0 && !editingBook.value)

const file = ref<File>()
const error = ref('')

// En création, le nom du fichier pré-remplit la recherche de métadonnées.
const searchTerm = computed(() =>
  file.value ? file.value.name.substring(0, file.value.name.lastIndexOf('.')) : '',
)

watch(
  () => props.id,
  (id) => {
    if (!id) {
      reset()
    }
  },
)

function setFile(newFile: File) {
  file.value = newFile
  error.value = ''
}

function submit(book: Book) {
  if (isEditing.value) {
    void saveEdit(book)
    return
  }

  if (!file.value) {
    return
  }

  useQueueStore().enqueue(book, file.value)
  reset()
}

async function saveEdit(book: Book) {
  error.value = ''

  try {
    await updateBook(props.id!, book)
    bookStore.$patch({ books: await fetchBooks() })
    router.push({ name: 'home' })
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'La modification a échoué.'
  }
}

function cancel() {
  if (isEditing.value) {
    router.push({ name: 'home' })
  } else {
    reset()
  }
}

function reset() {
  file.value = undefined
  error.value = ''
}
</script>

<template>
  <main :class="!file && !isEditing ? 'empty' : ''">
    <DropBox v-if="!file && !isEditing" @file-dropped="setFile" />

    <p v-else-if="notFound" class="error">Livre introuvable.</p>

    <BookForm
      v-else
      :initial="isEditing ? editingBook : null"
      :error="error"
      :search-term="searchTerm"
      @submit="submit"
      @cancel="cancel"
    />
  </main>
</template>

<style scoped>
main {
  display: flex;
  flex-direction: column;
  flex-grow: 1;
  justify-content: flex-start;
  align-items: center;
  gap: 0.5rem;
  padding: 1rem;
  overflow: auto;
  scrollbar-color: var(--color-amber) transparent;

  &.empty {
    justify-content: center;
    align-items: center;
  }

  .error {
    margin: 0;
    color: var(--color-text);
    background-color: oklch(48% 0.2 25 / 0.35);
    border: 1px solid oklch(63% 0.24 25);
    border-radius: 4px;
    padding: 0.375rem 0.75rem;
  }
}
</style>
