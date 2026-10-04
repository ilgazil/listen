<script setup lang="ts">
import IconStar from '@/components/icons/IconStar.vue'

const { ratings } = defineProps({
  ratings: Number,
})
</script>

<template>
  <span v-if="ratings">
    <IconStar :value="ratings" />
    <span class="value">{{ ratings }}</span>
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
