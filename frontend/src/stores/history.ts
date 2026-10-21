import { defineStore } from 'pinia'
import router from '@/router/index.ts'

const STORAGE_KEY = 'listen:history'

// Position courante dans la pile d'historique (vue-router la tient dans history.state).
function currentPosition(): number {
  const position: unknown = router.options.history.state?.position

  return typeof position === 'number' ? position : 0
}

// Position minimale déjà visitée, préservée au rechargement (sessionStorage est par onglet).
function restoreMin(): number {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY)

    if (raw) {
      const bounds = JSON.parse(raw) as { min?: unknown }

      if (typeof bounds.min === 'number') {
        return bounds.min
      }
    }
  } catch {
    // SessionStorage indisponible ou corrompu : on repart de zéro.
  }

  return Number.POSITIVE_INFINITY
}

export const useHistoryStore = defineStore('history', {
  state: () => ({
    position: currentPosition(),
    min: restoreMin(),
  }),
  getters: {
    canBack(state): boolean {
      return state.position > state.min
    },
  },
  actions: {
    // Mémorise la position et la borne minimale visitée (à appeler après chaque navigation).
    track(): void {
      this.position = currentPosition()
      this.min = Math.min(this.min, this.position)

      try {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ min: this.min }))
      } catch {
        // SessionStorage indisponible : la borne sera perdue au rechargement.
      }
    },

    // Retour à la page précédente, ou à l'accueil en accès direct (pas d'historique).
    backOrHome(): void {
      if (this.canBack) {
        router.back()
      } else {
        void router.push({ name: 'home' })
      }
    },
  },
})
