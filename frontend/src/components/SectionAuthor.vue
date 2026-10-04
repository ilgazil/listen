<script setup lang="ts">
import IconTypewriter from '@/components/icons/IconTypewriter.vue'

const { author } = defineProps({
  author: String,
})
</script>

<template>
  <span>
    <IconTypewriter />
    <span class="value">{{ author }}</span>
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
