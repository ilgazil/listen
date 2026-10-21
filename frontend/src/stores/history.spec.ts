import { describe, expect, it, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const mocks = vi.hoisted(() => ({
  back: vi.fn(),
  push: vi.fn(),
}))

// Pseudo-routeur : seule la surface utilisée par le store est simulée.
vi.mock('@/router/index.ts', () => ({
  default: {
    options: { history: { state: { position: 0 } } },
    ...mocks,
  },
}))

import router from '@/router/index.ts'
import { useHistoryStore } from '@/stores/history.ts'

function setPosition(position: number): void {
  ;(router.options.history.state as unknown as { position: number }).position = position
}

describe('history store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    sessionStorage.clear()
    mocks.back.mockReset()
    mocks.push.mockReset()
    setPosition(0)
  })

  it('ne peut pas revenir en arrière tant qu’aucune page n’a été visitée', () => {
    const history = useHistoryStore()
    history.track()

    expect(history.canBack).toBe(false)
    expect(mocks.back).not.toHaveBeenCalled()
  })

  it('suit la position et réactive le retour après une navigation', () => {
    const history = useHistoryStore()

    history.track() // chargement : position 0
    setPosition(1)
    history.track() // push d'une page

    expect(history.canBack).toBe(true)

    setPosition(0)
    history.track() // retour

    expect(history.canBack).toBe(false)
  })

  it('backOrHome() revient en arrière s’il y a un historique', () => {
    const history = useHistoryStore()
    history.track()
    setPosition(1)
    history.track()

    history.backOrHome()

    expect(mocks.back).toHaveBeenCalledTimes(1)
    expect(mocks.push).not.toHaveBeenCalled()
  })

  it('backOrHome() pousse l’accueil en accès direct (pas d’historique)', () => {
    const history = useHistoryStore()
    history.track()

    history.backOrHome()

    expect(mocks.back).not.toHaveBeenCalled()
    expect(mocks.push).toHaveBeenCalledWith({ name: 'home' })
  })

  it('restaure la borne depuis sessionStorage après un rechargement', () => {
    const history = useHistoryStore()
    history.track() // position 0
    setPosition(1)
    history.track() // position 1

    // Rechargement : nouvelle instance de store, même onglet, position relue du history.state.
    setActivePinia(createPinia())
    const reloaded = useHistoryStore()

    expect(reloaded.canBack).toBe(true)
  })

  it('ignore une session corrompue et repart de zéro', () => {
    sessionStorage.setItem('listen:history', 'not-json')

    const history = useHistoryStore()
    history.track()

    expect(history.canBack).toBe(false)
  })
})
