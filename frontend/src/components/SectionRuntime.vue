<script setup lang="ts">
import IconTime from '@/components/icons/IconTime.vue'

const { runtime } = defineProps({
  runtime: String,
})
</script>

<template>
  <span v-if="runtime">
    <IconTime />
    <span class="value">{{ runtime }}</span>
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
