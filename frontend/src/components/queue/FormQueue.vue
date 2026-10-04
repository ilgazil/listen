<script setup lang="ts">
import { statusLabel, useQueueStore } from '@/stores/queue'
import BookCard from '@/components/BookCard.vue'
import IconCloud from '@/components/icons/IconCloud.vue'
import IconTrash from '@/components/icons/IconTrash.vue'

const queueStore = useQueueStore()
</script>

<template>
  <div v-if="queueStore.uploads.length" class="wrapper">
    <div class="panel-header">
      <span>File d'envoi</span>
      <span class="count">{{ queueStore.uploads.length }}</span>
    </div>

    <div class="elements">
      <div v-for="(element, index) in queueStore.uploads" v-bind:key="index" class="element">
        <div
          class="preview"
          :class="{ ok: element.state === 'ended' && !element.failed, ko: element.state === 'error' || element.failed }"
        >
          <BookCard class="horizontal" :book="element.book" />

          <button
            type="button"
            class="remove-overlay"
            :disabled="!queueStore.canRemove(element)"
            :title="
              queueStore.canRemove(element)
                ? `Retirer ${element.book.title} de la file`
                : 'Envoi en cours, suppression impossible'
            "
            @click="queueStore.remove(element)"
          >
            <IconTrash />
          </button>
        </div>

        <div class="file" :title="element.file.name">
          <IconCloud variant="upload" />
          <span class="truncate">{{ element.file.name }}</span>
        </div>

        <p class="status">Statut : {{ statusLabel(element) }}</p>

        <div v-if="element.state === 'running'" class="progress">
          <progress :value="element.progress" max="100"></progress>
          <span>{{ element.progress }} %</span>
        </div>

        <ol v-if="element.log.length" class="log">
          <li v-for="(line, index) in element.log" :key="index">{{ line }}</li>
        </ol>

        <div class="controls">
          <button
            v-if="element.state === 'paused'"
            type="button"
            class="pause"
            @click="queueStore.resume(element)"
          >
            Reprendre
          </button>
          <button
            v-else-if="queueStore.canPause(element)"
            type="button"
            class="pause"
            @click="queueStore.pause(element)"
          >
            Pause
          </button>
        </div>
      </div>
    </div>

    <div class="actions">
      <button
        v-if="
          queueStore.uploads.some(
            ({ state }) => state === 'running' || state === 'pending' || state === 'draft',
          )
        "
        type="button"
        @click="queueStore.pauseAll()"
      >
        Pause
      </button>

      <button
        v-if="queueStore.launchable"
        type="button"
        class="primary"
        :disabled="queueStore.running"
        @click="queueStore.submit()"
      >
        Envoyer
      </button>
    </div>
  </div>
</template>

<style scoped>
.wrapper {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  width: 100%;
  color: var(--color-amber-lighter);

  .panel-header {
    flex: none;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8rem;
    padding: 0.75rem 1rem;

    .count {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 1.25rem;
      height: 1.25rem;
      padding: 0 0.25rem;
      border: 1px solid var(--color-amber);
      border-radius: 999px;
      font-size: 0.7rem;
      color: var(--color-amber);
    }
  }

  .elements {
    flex: 1 1 auto;
    min-height: 0;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    padding: 0.5rem 1rem 1rem;
    overflow-y: auto;
    overflow-x: hidden;
    scrollbar-color: var(--color-amber) transparent;
    scrollbar-width: thin;

    .element {
      min-width: 0;

      & > * {
        min-width: 0;
      }
    }
  }

  /* En dessous de 1024px (file sous le formulaire) : les drafts s'affichent par 2,
     au format tuile des suggestions (BookCard sans .horizontal), centrés dans leur cellule. */
  @media (max-width: 1023.98px) {
    .elements {
      flex-direction: row;
      flex-wrap: wrap;
      align-content: flex-start;

      .element {
        width: calc(50% - 0.5rem);
      }

      .preview {
        width: fit-content;
        margin: 0 auto;
      }
    }
  }

  button {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.75rem;
    font-size: 0.8rem;
    background-color: transparent;
    border: 1px solid var(--color-amber);
    border-radius: 999px;
    color: var(--color-amber);
    cursor: pointer;
    transition:
      background-color 0.3s,
      color 0.3s,
      box-shadow 0.3s;

    &:hover:not(:disabled) {
      color: var(--color-amber-lighter);
      box-shadow: 0 0 8px var(--color-amber-light);
    }

    &:disabled {
      opacity: 0.4;
      cursor: default;
    }

    &.primary {
      background-color: var(--color-amber);
      border-color: transparent;
      color: var(--color-amber-darker);

      &:hover:not(:disabled) {
        background-color: var(--color-amber-light);
        color: var(--color-amber-darker);
        box-shadow: 0 0 10px var(--color-amber-light);
      }
    }
  }

  .element {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;

    .preview {
      position: relative;
      border: 1px solid transparent;
      border-radius: 4px;
      transition:
        border-color 0.3s,
        box-shadow 0.3s;

      &.ok {
        border-color: var(--color-amber-lighter);
        box-shadow: 0 0 12px var(--color-amber-light);

        &::after {
          content: '✓';
          position: absolute;
          top: 0.375rem;
          right: 0.375rem;
          display: flex;
          align-items: center;
          justify-content: center;
          width: 1.25rem;
          height: 1.25rem;
          border-radius: 50%;
          background-color: var(--color-amber);
          color: var(--color-amber-darker);
          font-size: 0.8rem;
          line-height: 1;
        }
      }

      &.ko {
        border-color: oklch(72% 0.17 30);
        box-shadow: 0 0 12px oklch(60% 0.19 27 / 0.45);

        &::after {
          content: '⚠';
          position: absolute;
          top: 0.375rem;
          right: 0.375rem;
          display: flex;
          align-items: center;
          justify-content: center;
          width: 1.25rem;
          height: 1.25rem;
          border-radius: 50%;
          background-color: oklch(60% 0.19 27);
          color: oklch(25% 0.07 25);
          font-size: 0.75rem;
        }
      }

      .remove-overlay {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        border: none;
        border-radius: 4px;
        background-color: oklch(20% 0.04 45.904 / 0.6);
        backdrop-filter: blur(2px);
        color: var(--color-amber-lighter);
        cursor: pointer;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.3s;

        svg {
          width: 1.5rem;
          height: 1.5rem;
        }
      }
    }

    .preview:hover .remove-overlay {
      opacity: 1;
      pointer-events: auto;
    }

    .remove-overlay:hover {
      background-color: oklch(25% 0.05 45.904 / 0.7);
      color: var(--color-amber);
    }

    .remove-overlay:disabled {
      cursor: not-allowed;
      color: oklch(70% 0.05 45.904 / 0.5);
    }

    .file {
      display: flex;
      align-items: center;
      gap: 0.125rem;
      min-width: 0;
      font-size: 0.7rem;
      color: var(--color-amber);

      svg {
        flex: none;
      }

      .truncate {
        min-width: 0;
      }
    }

    .status {
      margin: 0;
      font-size: 0.7rem;
      opacity: 0.9;
    }

    .controls {
      display: flex;
      flex-wrap: wrap;
      gap: 0.375rem;
      align-items: flex-start;

      .remove {
        margin-left: auto;
      }
    }

    .progress {
      display: flex;
      align-items: center;
      gap: 0.5rem;

      progress {
        flex: 1;
        height: 0.5rem;
        accent-color: var(--color-amber);
      }

      span {
        font-size: 0.75rem;
        color: var(--color-amber);
      }
    }

    .log {
      margin: 0;
      padding-left: 0.75rem;
      list-style: none;
      display: flex;
      flex-direction: column;
      gap: 0.125rem;
      font-size: 0.7rem;
      color: var(--color-amber-lighter);

      li::before {
        content: '» ';
        color: var(--color-amber);
      }
    }
  }

  .actions {
    flex: none;
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    border-top: 1px solid oklch(50% 0.11 45.904);
  }
}
</style>
