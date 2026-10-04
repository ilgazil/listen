<script setup lang="ts">
import { ref, watch } from 'vue'
import IconCamera from '@/components/icons/IconCamera.vue'

const props = defineProps({
  src: {
    type: String,
    default: '',
  },
  alt: {
    type: String,
    default: '',
  },
})

const coverFailed = ref(false)

watch(
  () => props.src,
  () => {
    coverFailed.value = false
  },
)
</script>

<template>
  <img v-if="src && !coverFailed" :src="src" :alt="alt" @error="coverFailed = true" />
  <div v-else class="placeholder" aria-hidden="true">
    <IconCamera />
  </div>
</template>

<style scoped>
img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  aspect-ratio: 1;
  border: 1px dashed var(--color-amber);
  color: var(--color-amber);
  opacity: 0.6;

  svg {
    width: 40%;
    height: 40%;
    min-width: 1.5rem;
    min-height: 1.5rem;
  }
}
</style>
