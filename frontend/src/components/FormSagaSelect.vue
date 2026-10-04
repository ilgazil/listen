<script setup lang="ts">
import { computed, ref } from 'vue'
import { useBookStore } from '@/stores/book.ts'

const bookStore = useBookStore()
const model = defineModel<string>()

const open = ref(false)

const filteredSagas = computed(() => {
  const q = (model.value || '')
    .trim()
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')

  if (!q) {
    return bookStore.sagas
  }

  return bookStore.sagas.filter((s) =>
    s.name
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .includes(q),
  )
})

function onInput(event: Event) {
  model.value = (event.target as HTMLInputElement).value
  open.value = true
}

function select(name: string) {
  model.value = name
  open.value = false
}

function onFocus() {
  open.value = true
}

function onBlur() {
  setTimeout(() => {
    open.value = false
  }, 150)
}
</script>

<template>
  <div class="combobox">
    <input
      type="text"
      :value="model"
      @input="onInput"
      @focus="onFocus"
      @blur="onBlur"
      @keydown.escape="open = false"
      placeholder="Choisir ou créer une saga..."
    />

    <div class="dropdown" v-show="open && filteredSagas.length > 0">
      <button
        v-for="saga in filteredSagas"
        :key="saga.id"
        type="button"
        @mousedown.prevent="select(saga.name)"
      >
        {{ saga.name }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.combobox {
  position: relative;
  width: 100%;
}

input {
  width: 100%;
}

.dropdown {
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

    &:hover {
      background-color: var(--color-amber);
      color: var(--color-amber-darker);
    }
  }
}
</style>
