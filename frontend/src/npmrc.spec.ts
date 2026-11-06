// @vitest-environment node
import { describe, expect, it } from 'vitest'

// Garde-fou supply chain : la politique « min age » (miroir du cooldown composer
// côté backend) empêche la résolution de versions publiées depuis moins de 5 jours.
// npm 12 l'applique nativement à install/update (testé : --min-release-age=5200 →
// ENOVERSIONS) ; npm-check-updates la lit aussi pour les bumps de versions.
// NB : .npmrc est dans la deny-list d'import de Vite (tokens npm) et le tsconfig app
// n'embarque pas les types node (DOM uniquement — une référence globale casserait le
// typage de setTimeout) → suppression localisée, lecture fs dans l'env vitest « node ».
// @ts-expect-error — types node volontairement absents du tsconfig app
import { readFileSync } from 'node:fs'

describe('.npmrc (politique supply chain)', () => {
  it('impose min-release-age=5 jours', () => {
    const npmrc = readFileSync(new URL('../.npmrc', import.meta.url), 'utf8')

    expect(npmrc).toMatch(/^min-release-age=5$/m)
  })
})
