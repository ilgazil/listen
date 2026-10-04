<script setup lang="ts">
import { computed, type PropType } from 'vue'
import IconAudible from '@/components/icons/IconAudible.vue'
import IconLizzie from '@/components/icons/IconLizzie.vue'
import { Book } from '@/entities/book.ts'

const { book } = defineProps({
  book: Object as PropType<Book>,
})

const isAudible = computed(() => book?.scraper === 'audible')
const isLizzie = computed(() => book?.scraper === 'lizzie')

const url = computed(() => {
  if (isAudible.value) {
    return `https://www.audible.fr/pd/Book/${book?.scrapId}`
  }

  if (isLizzie.value) {
    return `https://www.lizzie.audio/content/${book?.scrapId}`
  }

  return ''
})

const label = computed(() => {
  if (isAudible.value) {
    return 'Audible'
  }

  if (isLizzie.value) {
    return 'Lizzie'
  }

  return ''
})
</script>

<template>
  <a class="neon" :href="url" target="_blank" rel="noopener noreferrer">
    <IconAudible v-if="isAudible" />
    <IconLizzie v-if="isLizzie" />
    {{ label }}
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
