<script setup lang="ts">
import { computed } from 'vue'

const { value = 0 } = defineProps({
  value: Number,
})

const isEmpty = computed(() => value < 4)
const isHalfFull = computed(() => !isEmpty.value && value < 5)
const isFull = computed(() => value === 5)
</script>

<template>
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="icon">
    <path
      v-if="isEmpty"
      d="M12,15.39L8.24,17.66L9.23,13.38L5.91,10.5L10.29,10.13L12,6.09L13.71,10.13L18.09,10.5L14.77,13.38L15.76,17.66M22,9.24L14.81,8.63L12,2L9.19,8.63L2,9.24L7.45,13.97L5.82,21L12,17.27L18.18,21L16.54,13.97L22,9.24Z"
    />
    <path
      v-else-if="isHalfFull"
      d="M12,15.4V6.1L13.71,10.13L18.09,10.5L14.77,13.39L15.76,17.67M22,9.24L14.81,8.63L12,2L9.19,8.63L2,9.24L7.45,13.97L5.82,21L12,17.27L18.18,21L16.54,13.97L22,9.24Z"
    />
    <path
      v-else-if="isFull"
      d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"
    />
  </svg>
</template>
