<script setup lang="ts">
import { Book } from '@/entities/book.ts'
import SectionRatings from '@/components/SectionRatings.vue'
import SectionAuthor from '@/components/SectionAuthor.vue'
import SectionNarrator from '@/components/SectionNarrator.vue'
import SectionRuntime from '@/components/SectionRuntime.vue'
import SectionSaga from '@/components/SectionSaga.vue'
import IconEdit from '@/components/icons/IconEdit.vue'
import CoverImage from '@/components/CoverImage.vue'

const { book } = defineProps({
  book: Book,
})
</script>

<template>
  <article v-if="book">
    <h2>
      <span class="truncate">{{ book.title }}</span>
      <SectionRatings :ratings="book.ratings" class="ratings" />
    </h2>

    <div class="book-core">
      <div>
        <CoverImage class="cover" :src="book.cover" :alt="book.title" />
      </div>

      <div>
        <div>
          <div class="section-title">Auteur</div>
          <SectionAuthor :author="book.author" />
        </div>

        <div>
          <div class="section-title">
            <span v-if="book.narrators.length === 1">Narrateur</span>
            <span v-else>Narrateurs</span>
          </div>
          <SectionNarrator :narrators="book.narrators" />
        </div>

        <div v-if="book.saga">
          <div class="section-title">Saga</div>
          <SectionSaga :saga="book.saga" />
        </div>

        <div v-if="book.hasRuntime">
          <div class="section-title">Durée</div>
          <SectionRuntime :runtime="book.runtime" />
        </div>
      </div>
    </div>

    <div class="book-footer">
      <slot></slot>

      <RouterLink
        v-if="book.id"
        :to="{ name: 'edit', params: { id: book.id } }"
        class="neon edit"
        title="Modifier"
      >
        <IconEdit />
        Modifier
      </RouterLink>
    </div>
  </article>
</template>

<style scoped>
article {
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
    cursor: default;

    span {
      flex-grow: 1;
      padding: 0 0.5rem;
      text-align: center;
    }

    .ratings {
      flex: none;
      font-size: 0.9rem;
      color: var(--color-amber);
    }
  }

  .book-core {
    display: flex;
    flex-wrap: wrap;
    width: 100%;
    flex-grow: 1;
    align-items: flex-start;
    gap: 0.5rem;

    & > div {
      flex: 1 1 calc(50% - 0.25rem);
      min-width: 10.5rem;

      > span {
        display: flex;
        align-items: center;
        gap: 0.125rem;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
    }

    .cover {
      border-radius: 4px;
    }

    .section-title {
      font-size: 0.8rem;
      color: var(--color-dark);
      margin-right: 0.125rem;
      cursor: default;
    }
  }

  .book-footer {
    position: relative;
    display: flex;
    justify-content: space-between;
    width: 100%;
    padding: 0 0.5rem;

    .action {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .edit {
      position: absolute;
      left: 50%;
      transform: translateX(-50%);
      display: flex;
      align-items: center;
      gap: 0.25rem;
    }
  }
}
</style>
