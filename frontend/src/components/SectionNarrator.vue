<script setup lang="ts">
import { computed, type PropType } from 'vue'
import type { Book } from '@/entities/book.ts'
import IconSpeech from '@/components/icons/IconSpeech.vue'

const { narrators } = defineProps({
  narrators: Array as PropType<Book['narrators']>,
})

const output = computed(() => {
  if (!narrators) {
    return ''
  }

  if (narrators.length === 1) {
    return narrators[0]
  }

  if (narrators.length === 2) {
    return narrators[0].concat(' et ').concat(narrators[1])
  }

  const others = narrators.slice().splice(1)

  return narrators
    .slice(0, 1)
    .join(', ')
    .concat(' et ')
    .concat(others.length === 1 ? '1 autre' : `${others.length} autres`)
})
</script>

<template>
  <span>
    <IconSpeech />
    <span class="value">{{ output }}</span>
  </span>
</template>

<style scoped>
span {
  display: flex;
  align-items: center;
  gap: 0.125rem;

  .value {
    display: inline;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}
</style>
