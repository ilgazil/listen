<script setup lang="ts">
import { type PropType } from 'vue'
import { Book } from '@/entities/book.ts'
import IconDownload from '@/components/icons/IconDownload.vue'

const { book } = defineProps({
  book: Object as PropType<Book>,
})
</script>

<template>
  <a class="neon" :href="'/api/download/' + book?.id" target="_blank">
    <IconDownload />
    Télécharger
  </a>
</template>

<style scoped>
a {
  display: flex;
  align-items: center;
  flex: none;
  gap: 0.25rem;
}
</style>
