<script setup lang="ts">
import { type PropType } from 'vue'
import { Book } from '@/entities/book'
import SectionAuthor from '@/components/SectionAuthor.vue'
import SectionSaga from '@/components/SectionSaga.vue'
import CoverImage from '@/components/CoverImage.vue'

defineProps({
  book: Object as PropType<Book>,
})
</script>

<template>
  <article v-if="book">
    <CoverImage class="cover" :src="book.cover" :alt="book.title" />

    <div class="details">
      <h3 :title="book.title">{{ book.title }}</h3>

      <SectionSaga v-if="book.saga" class="saga" :saga="book.saga" />

      <SectionAuthor class="author" :author="book.author" />
    </div>
  </article>
</template>

<style scoped>
/*
 * Deux dispositions pour une même carte :
 * - tuile verticale (défaut) : suggestions du formulaire, et file d'envoi en
 *   mode responsive (sous 1024px, la file passe sous le formulaire) ;
 * - carte horizontale compacte (variante .horizontal) : file d'envoi dans la
 *   sidebar, au-dessus de 1024px uniquement.
 */
article {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.25rem;
  min-width: 0;
  width: 9rem;
  height: 14rem;
  padding: 0.375rem;
  border: 1px solid var(--color-amber);
  border-radius: 4px;
  overflow: hidden;

  > .cover {
    flex: none;
    width: 100%;
    height: 8.5rem;
    border-radius: 4px;
  }

  .details {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.25rem;
    width: 100%;
    min-width: 0;

    h3 {
      width: 100%;
      margin: 0;
      font-size: 0.8rem;
      font-weight: 600;
      line-height: 1.2;
      text-align: center;
      color: var(--color-amber);
      display: -webkit-box;
      -webkit-line-clamp: 2;
      line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    /* En tuile, on reste au format « suggestion » : pas de saga. */
    .saga {
      display: none;
    }

    .author {
      display: block;
      width: 100%;
      min-width: 0;
      font-size: 0.75rem;
      line-height: 1.2;
      text-align: center;
      color: var(--color-amber);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
  }

  /* Carte horizontale : file d'envoi en sidebar (grand écran). */
  @media (min-width: 1024px) {
    &.horizontal {
      flex-direction: row;
      align-items: center;
      gap: 0.5rem;
      width: 100%;
      height: auto;
      padding: 0.5rem;

      > .cover {
        width: 4rem;
        height: 4rem;
      }

      .details {
        flex: 1 1 auto;
        align-items: flex-start;
        gap: 0.125rem;
        overflow: hidden;

        h3 {
          font-size: 0.9rem;
          font-weight: normal;
          font-style: italic;
          text-align: left;
          color: inherit;
          display: block;
          white-space: nowrap;
          text-overflow: ellipsis;
        }

        .saga {
          display: flex;
          width: 100%;
          font-size: 0.9rem;
        }

        .author {
          display: flex;
          width: 100%;
          font-size: 0.9rem;
          color: inherit;
        }
      }
    }
  }
}
</style>
