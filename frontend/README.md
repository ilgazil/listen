# 🎧 Frontend — Vue 3 / Vite

Frontend de **Listen**, bibliothèque de livres audio. Queue d'upload, recherche et mapping via le scraper backend.

## Stack

- Vue 3 + TypeScript, Vite 8, Pinia
- Vitest 5 + @vue/test-utils (18 tests)

## Scripts

```bash
npm install
npm run dev          # Vite, http://localhost:5173
npm run type-check   # vue-tsc
npm run build        # type-check + build
npm run lint         # eslint --fix
npm run format       # prettier --write src/
npm run test         # vitest (src/**/*.spec.ts)
```

## Workflow local

1. Backend Symfony sur le port 8000 (voir `../backend/README.md`)
2. `npm run dev` puis ouvrir http://localhost:5173

> Toutes ces commandes sont aussi accessibles via le `Makefile` à la racine du repo (`make backend`, `make frontend`, `make test`…). Détails : `AGENTS.md` et `SPECS.md`.