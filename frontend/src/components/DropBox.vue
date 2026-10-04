<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import IconCloud from '@/components/icons/IconCloud.vue'

const emit = defineEmits<{
  'file-dropped': [file: File]
}>()

let activeTimeout = 0

const active = ref(false)
const error = ref('')

function acceptFile(file: File | null | undefined): void {
  if (!file) {
    return
  }

  if (!file.name.toLowerCase().endsWith('.zip')) {
    error.value = 'Seuls les fichiers .zip sont acceptés.'
    return
  }

  error.value = ''
  emit('file-dropped', file)
}

function setActive() {
  active.value = true
  clearTimeout(activeTimeout)
}
function setInactive() {
  activeTimeout = setTimeout(() => (active.value = false), 50)
}

function onDrop(event: DragEvent) {
  setInactive()
  acceptFile(event.dataTransfer?.files[0])
}

function onFileSelect(event: Event) {
  if (event.target instanceof HTMLInputElement) {
    acceptFile(event.target.files?.[0])
  }
}

const bodyEvents = ['dragenter', 'dragover', 'dragleave', 'drop'] as const

onMounted(() => {
  bodyEvents.forEach((eventName) =>
    document.body.addEventListener(eventName, (event: Event) => event.preventDefault()),
  )
})

onUnmounted(() => {
  bodyEvents.forEach((eventName) =>
    document.body.removeEventListener(eventName, (event: Event) => event.preventDefault()),
  )
})
</script>

<template>
  <label
    :data-active="active"
    @dragenter.prevent="setActive"
    @dragover.prevent="setActive"
    @dragleave.prevent="setInactive"
    @drop.prevent="onDrop"
  >
    <input type="file" accept=".zip" @change="onFileSelect" />
    <IconCloud variant="upload" />
    <span class="hint">Déposez votre archive <b>.zip</b></span>
  </label>
  <p v-if="error" class="error">{{ error }}</p>
</template>

<style scoped>
.error {
  margin: 0;
  max-width: 12rem;
  color: oklch(70% 0.19 30);
  font-size: 0.8rem;
}

label {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  height: 12rem;
  width: 12rem;
  border: 3px dashed var(--color-amber);
  border-radius: 0.25rem;
  cursor: pointer;

  input {
    display: none;
  }

  svg {
    color: var(--color-amber);
    height: 55%;
    width: 55%;
  }

  .hint {
    color: var(--color-amber);
    font-size: 0.85rem;
    text-align: center;
    line-height: 1.4;

    b {
      color: var(--color-amber);
      letter-spacing: 0.05em;
    }
  }

  &:hover {
    border: 3px dashed var(--color-amber-light);

    svg,
    .hint {
      color: var(--color-amber-light);
    }
  }
}
</style>
