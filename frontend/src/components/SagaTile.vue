<script setup lang="ts">
import { computed, type PropType } from 'vue'
import type { Saga } from '@/entities/saga.ts'
import type { SagaBook } from '@/entities/book.ts'
import IconBookshelf from '@/components/icons/IconBookshelf.vue'
import SectionAuthor from '@/components/SectionAuthor.vue'
import SectionNarrator from '@/components/SectionNarrator.vue'
import SectionRuntime from '@/components/SectionRuntime.vue'
import CoverImage from '@/components/CoverImage.vue'

const props = defineProps({
  saga: Object as PropType<Saga>,
  books: Array as PropType<Array<SagaBook>>,
})

const covers = computed(() => {
  const list = props.books ?? []
  const amount = list.length <= 4 ? 4 : 3

  return list.slice(0, amount).map((book) => book.cover)
})

const author = computed(() => props.books?.[0]?.author ?? '')
const narrators = computed(() => {
  const set = new Set<string>()

  for (const book of props.books ?? []) {
    for (const narrator of book.narrators) {
      set.add(narrator)
    }
  }

  return Array.from(set)
})
const count = computed(() => props.books?.length ?? 0)
const label = computed(() => `${count.value} livre${count.value > 1 ? 's' : ''}`)
const showOverlay = computed(() => count.value > 4)
const extra = computed(() => count.value - 3)
const runtime = computed(() => computeRuntime(props.books ?? []))

function computeRuntime(books: Array<SagaBook>): string {
  let minutes = books.reduce((total, book) => {
    const [hours, minutes] = book.runtime.split(':')

    return total + Number(hours) * 60 + Number(minutes)
  }, 0)

  const hours = Math.floor(minutes / 60)
  const days = Math.floor(hours / 24)

  if (days > 2) {
    return `+ de ${days} jours`
  }

  minutes -= hours * 60

  return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`
}
</script>

<template>
  <article v-if="saga" class="saga-tile">
    <h2>
      <span class="truncate">{{ saga.name }}</span>
    </h2>

    <div class="book-core">
      <div>
        <div class="covers">
          <CoverImage
            v-for="(cover, index) of covers.slice(0, 3)"
            :key="index"
            :src="cover"
            :alt="saga.name"
          />

          <div class="cell">
            <CoverImage v-if="showOverlay" :src="books?.[3]?.cover" class="dim" :alt="saga.name" />
            <CoverImage v-else-if="covers[3]" :src="covers[3]" :alt="saga.name" />
            <span v-if="showOverlay" class="more">+{{ extra }}</span>
          </div>
        </div>
      </div>

      <div>
        <div>
          <div class="section-title">Auteur</div>
          <SectionAuthor :author="author" />
        </div>

        <div>
          <div class="section-title">
            <span v-if="narrators.length === 1">Narrateur</span>
            <span v-else>Narrateurs</span>
          </div>
          <SectionNarrator :narrators="narrators" />
        </div>

        <div>
          <div class="section-title">Livres</div>
          <span class="books">
            <IconBookshelf />
            {{ label }}
          </span>
        </div>

        <div>
          <div class="section-title">Durée</div>
          <SectionRuntime :runtime="runtime" />
        </div>
      </div>
    </div>

    <div class="book-footer">
      <RouterLink :to="`/saga/${saga.id}`" class="neon action">
        <IconBookshelf />
        Voir les livres
      </RouterLink>
    </div>
  </article>
</template>

<style scoped>
.saga-tile {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  width: 28rem;
  max-width: 100%;
  padding: 0.5rem;
  border: 1px solid var(--color-amber);
  border-radius: 4px;
  transition: 0.4s;

  &:hover {
    box-shadow: 0 0 8px var(--color-amber-light);
  }

  h2 {
    display: flex;
    align-items: center;
    width: 100%;
    color: var(--color-amber);

    span {
      flex-grow: 1;
      padding: 0 0.5rem;
      text-align: center;
    }
  }

  .book-core {
    display: flex;
    flex-wrap: wrap;
    flex-grow: 1;
    align-items: flex-start;
    gap: 0.5rem;

    & > div {
      flex: 1 1 calc(50% - 0.25rem);
      min-width: 10.5rem;
    }

    .section-title {
      font-size: 0.8rem;
      color: var(--color-dark);
      margin-right: 0.125rem;
      cursor: default;
    }

    .books {
      display: flex;
      align-items: center;
      gap: 0.125rem;
    }
  }

  .covers {
    display: flex;
    flex-wrap: wrap;
    gap: 2px;
    width: 100%;

    > * {
      width: calc(50% - 1px);
      aspect-ratio: 1;
      border-radius: 2px;
    }

    .cell {
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;

      .dim {
        opacity: 0.2;
      }

      .more {
        position: absolute;
        font-size: 2rem;
        color: var(--color-amber);
      }
    }
  }

  .book-footer {
    display: flex;
    justify-content: space-between;
    width: 100%;
    padding: 0 0.5rem;

    .action {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
  }
}
</style>
