<script setup lang="ts">
import { Book } from '@/entities/book.ts'
import SectionRatings from '@/components/SectionRatings.vue'
import SectionAuthor from '@/components/SectionAuthor.vue'
import SectionNarrator from '@/components/SectionNarrator.vue'
import SectionRuntime from '@/components/SectionRuntime.vue'
import SectionSaga from '@/components/SectionSaga.vue'
import IconEdit from '@/components/icons/IconEdit.vue'
import IconTag from '@/components/icons/IconTag.vue'
import CoverImage from '@/components/CoverImage.vue'
import { useRouter } from 'vue-router'

// linkTags=false (preview du formulaire) : le compteur reste informatif —
// tooltip au survol du compteur seul, tags non cliquables.
const { book, linkTags = true } = defineProps({
  book: Book,
  linkTags: { type: Boolean, default: true },
})

const router = useRouter()

function goToTag(tag: string): void {
  if (!linkTags || !router) {
    return
  }

  // Retour à l'index avec le filtre tag actif (?tag=…).
  void router.push({ name: 'home', query: { tag } })
}
</script>

<template>
  <article v-if="book">
    <h2>
      <span class="truncate">{{ book.title }}</span>
      <SectionRatings :ratings="book.ratings" class="ratings" />

      <span v-if="book.tags.length" class="tags" :class="{ link: linkTags }">
        <IconTag />
        <span class="value">{{ book.tags.length }}</span>

        <span class="tooltip">
          <button
            v-for="tag of book.tags"
            v-bind:key="tag"
            type="button"
            class="tag"
            @click="goToTag(tag)"
          >
            {{ tag }}
          </button>
        </span>
      </span>
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

    /* Compteur de tags façon note : icône étiquette + nombre, sans cadre.
       Les sélecteurs de classe passent outre le `h2 span` générique
       (flex-grow/padding/text-align) — tout est réétabli explicitement. */
    .tags {
      position: relative;
      display: flex;
      align-items: center;
      gap: 0.125rem;
      flex: none;
      margin-left: 0.375rem;
      font-size: 0.9rem;
      color: var(--color-amber);
      white-space: nowrap;

      .value {
        display: inline;
        flex-grow: 0;
        min-width: 0;
        padding: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        text-align: left;
        white-space: nowrap;
      }

      .tooltip {
        position: absolute;
        top: calc(100% + 4px);
        right: 0;
        display: flex;
        flex-direction: column;
        /* Le panneau épouse le tag le plus long : un tag tient toujours en
           entier sur la fiche (aucun plafond en bibliothèque). white-space
           normal : le nowrap du compteur ne doit pas descendre dans le
           panneau (sinon les tags longs débordent au lieu de passer à la
           ligne quand le plafond de la preview s'applique). */
        width: max-content;
        white-space: normal;
        padding: 0.25rem 0.375rem;
        background-color: var(--color-background-mute);
        border: 1px solid var(--color-amber);
        border-radius: 4px;
        z-index: 20;
        text-align: left;
        visibility: hidden;
        opacity: 0;
        transition:
          opacity 0.15s,
          visibility 0.15s;
        pointer-events: none;

        /* Pont invisible reliant compteur et panneau : en variante lien, le
           survol ne coupe pas dans les 4 px d'écart quand on descend vers les
           tags (inerte sinon — pointer-events: none hérité). */
        &::before {
          content: '';
          position: absolute;
          top: -4px;
          left: 0;
          right: 0;
          height: 4px;
        }

        .tag {
          flex: none;
          padding: 0.125rem 0.375rem;
          text-align: left;
          color: var(--color-text);
          font-size: inherit;
          background: none;
          border: none;
          cursor: default;
        }
      }

      &:hover .tooltip {
        visibility: visible;
        opacity: 1;
      }
    }

    /* Fiche de bibliothèque (link-tags) : les tags du panneau sont cliquables
       et renvoient vers l'index filtré — petit glow au survol, et le panneau
       est survolable sans se refermer (pont + pointer-events). */
    .tags.link .tooltip {
      pointer-events: auto;

      .tag {
        cursor: pointer;

        &:hover {
          color: var(--color-amber-lighter);
          text-shadow: 0 0 8px var(--color-amber-light);
        }
      }
    }

    /* Preview du formulaire (sans `link`) : plafond à 16rem — un tag très
       long passe à la ligne au lieu de déborder de la preview. */
    .tags:not(.link) .tooltip {
      max-width: 16rem;

      .tag {
        overflow-wrap: anywhere;
      }
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
