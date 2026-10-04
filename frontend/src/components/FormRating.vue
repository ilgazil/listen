<script setup lang="ts">
import { ref } from 'vue'

const model = defineModel<number>({ default: 0 })

const hoverValue = ref(0)

const starPath =
  'M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z'

function getFill(star: number): number {
  const v = hoverValue.value || model.value
  return Math.min(100, Math.max(0, (v - (star - 1)) * 100))
}

function onStarClick(star: number, event: MouseEvent) {
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect()
  const isLeftHalf = event.clientX - rect.left < rect.width / 2
  const clicked = isLeftHalf ? star - 0.5 : star
  model.value = model.value === clicked ? 0 : clicked
}

function onStarHover(star: number, event: MouseEvent) {
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect()
  const isLeftHalf = event.clientX - rect.left < rect.width / 2
  hoverValue.value = isLeftHalf ? star - 0.5 : star
}

function clear() {
  model.value = 0
}
</script>

<template>
  <div class="form-rating">
    <div class="stars" @mouseleave="hoverValue = 0">
      <button
        v-for="i in 5"
        :key="i"
        type="button"
        :class="['star', { 'has-fill': getFill(i) > 0 }]"
        :style="{ '--fill': getFill(i) + '%' }"
        @click="onStarClick(i, $event)"
        @mousemove="onStarHover(i, $event)"
      >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
          <path class="empty" :d="starPath" />
          <path class="filled" :d="starPath" />
        </svg>
      </button>
    </div>

    <button v-if="model" type="button" class="clear" @click="clear">×</button>
  </div>
</template>

<style scoped>
.form-rating {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.stars {
  display: flex;
  gap: 0.125rem;
}

.star {
  padding: 0;
  background: none;
  border: none;
  cursor: pointer;
  line-height: 0;

  svg {
    width: 1.25rem;
    height: 1.25rem;
  }

  .empty {
    fill: none;
    stroke: var(--color-amber);
    stroke-width: 1.5;
    stroke-linejoin: round;
    opacity: 0.9;
  }

  .filled {
    fill: var(--color-amber);
    clip-path: inset(0 calc(100% - var(--fill)) 0 0);
  }

  &:hover .empty {
    stroke: var(--color-amber-light);
  }
}

.stars:hover .star.has-fill {
  filter: drop-shadow(0 0 4px var(--color-amber-light));
}

.stars:hover .star .empty {
  opacity: 1;
}

.clear {
  padding: 0;
  background: none;
  border: none;
  color: var(--color-amber);
  cursor: pointer;
  font-size: 1rem;
  line-height: 1;
  opacity: 0.85;

  &:hover {
    color: var(--color-amber-lighter);
    opacity: 1;
  }
}
</style>
