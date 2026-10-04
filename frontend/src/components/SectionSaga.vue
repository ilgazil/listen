<script setup lang="ts">
import { computed, type PropType } from 'vue'
import IconBookshelf from '@/components/icons/IconBookshelf.vue'
import type { Saga } from '@/entities/saga.ts'

const { saga } = defineProps({
  saga: Object as PropType<Saga>,
})

const output = computed(() => {
  let output = ''

  if (saga) {
    output = saga.name

    if (saga.tome) {
      output = output.concat(`, tome ${saga.tome}`)
    }
  }

  return output
})
</script>

<template>
  <span v-if="saga">
    <IconBookshelf />
    <span class="value">{{ output }}</span>
  </span>
</template>

<style scoped>
span {
  display: flex;
  align-items: flex-start;
  gap: 0.125rem;

  > *:first-child {
    margin-top: 0.3rem;
  }

  .value {
    display: inline;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}
</style>
