# AGENTS.md — Frontend (Vue 3)

Frontend de **listen** : bibliothèque personnelle de livres audio (Audible.fr / Lizzie.audio).
L'app permet de parcourir sa bibliothèque, rechercher/éditer les métadonnées d'un livre et l'envoyer vers 1Fichier avec notification Discord.

**Lire `SPECS.md` AVANT d'écrire la moindre ligne de code** : c'est la spec de référence
(comportement actuel documenté + fonctionnalités cibles + décisions ouvertes). Le AGENTS.md ci-dessous est le mode d'emploi
opérationnel ; `SPECS.md` décrit le *quoi*.

---

## Vue d'ensemble

- **Stack** : Vue 3.5 + Vite 8 + TypeScript 6 + Pinia 4 + Vue Router 5.
- **Thème** : Diablo II / Deckard Cain — header « Hello my friend, stay awhile and listen. » (image de fond
  `deckard-cain.jpg`), néons ambre (`var(--color-amber)`, oklch), texte français.
- **Backend** : Symfony (`../backend`), adresse de l'API pilotée par `VITE_API_BASE_URL` (proxy dev `/api` →
  `http://localhost:8000` par défaut ; conf locale `.env.development.local`).
- **Déploiement** : build copié dans `backend/public` (monolithe), ou servi par Symfony.

---

## Commandes

> Toutes aussi dispo via `make frontend` / `make test`… à la racine du repo.

```bash
npm install
npm run dev            # Vite, port 5173
npm run type-check     # vue-tsc --build
npm run build          # type-check + build
npm run lint           # eslint --fix
npm run format         # prettier --write src/
npm run test           # vitest run (dossier src/**/*.spec.ts)
```

## Workflow local (dev)

1. Backend Symfony en local (il lit la base de prod via `backend/.env.dev`) :
   ```bash
   cd ../backend && symfony serve        # http://localhost:8000
   ```
2. Frontend (Vite, port 5173) :
   ```bash
   npm run dev                           # http://localhost:5173
   ```

L'adresse de l'API est pilotée par **`VITE_API_BASE_URL`** (voir « Config API » ci-dessous). Par défaut en dev le
front tape le backend local via le proxy vite (`/api` → `http://localhost:8000`). Si tu veux appeler directement le
backend local (sans proxy), renseigne la conf locale : `echo "VITE_API_BASE_URL='http://localhost:8000'" > .env.development.local`.

---

## Conventions de code

- **Prettier** (`.prettierrc.json`) : `semi: false`, `singleQuote: true`, `printWidth: 100`.
- **ESLint** (flat) : `eslint-plugin-vue` (flat/essential) + `@vue/eslint-config-typescript` + skip-formatting.
- Alias `@/*` → `src/*` (tsconfig + vite). Imports de fichiers TS en `.ts` explicite (`@/api/book.ts`).
- UI entièrement **en français** (labels, statuts, messages).
- **Langues** : code en anglais (identifiants, noms de fichiers), commentaires en français, docs en français.
- Composants : PascalCase, un composant = un rôle. Icons : petits composants SVG dans `components/icons/`.
- Styles : CSS scoped, variables de thème dans `src/assets/main.css`, classe utilitaire `.neon`, `.truncate`.

## Upgrade des dépendances

- **Politique « min age »** : `.npmrc` impose `min-release-age=5` (jours) — aucune dépendance publiée il y a moins de
  5 jours n'est installée/upgradée (`npm install`, `npm update`). `npx npm-check-updates` lit le même `.npmrc`
  (`--cooldown 5` implicite) pour monter aux dernières versions éligibles. Bypass ponctuel :
  `npm install <pkg>@<version> --min-release-age=0`.
- **`npm-check-updates`** (`npx npm-check-updates -u` puis `npm install`) est utilisé pour les montées majeures.
  Toute mise à jour doit repasser `npm run type-check`, `npm run lint`, `npm run build`, `npm run test` ; ré-épingler
  un paquet qui casse (ex. `typescript@7` reste bloqué à `^6` car `vue-tsc` dépend du chemin interne `./lib/tsc`).
- **`allowScripts` (npm 12)** : `package.json` documente la politique de scripts. `fsevents` est en `false` (deny) :
  npm 12 omet le binaire précompilé d'un paquet dont le script d'install est bloqué/refusé ; le `deny` explicite
  conserve `fsevents.node` du tarball (pas de `node-gyp rebuild`, fragile sous CLT).

---

## Structure

```
src/
├── api/            # couche API (core.ts helpers, book.ts endpoints + mapping)
├── assets/         # main.css (thème), base.css, images
├── components/     # BookLarge/BookCard, DropBox, Form*, Link*, Section*, queue/FormQueue, icons/
├── entities/       # Book, Saga (classes) + type guard isSagaBook
├── router/         # routes "/" (HomeView), "/add" (FormView), "/edit/:id", "/saga/:id", "/orphans" (OrphansView), "/intruders" (IntrudersView)
├── stores/         # Pinia: book.ts (bibliothèque), queue.ts (file d'envoi)
└── views/          # HomeView, FormView, SagaView, OrphansView, IntrudersView
```

---

## API (contrat)

- `GET /api/books?pagination=false` → `{ member: Book[] }` — liste de la bibliothèque (`fetchBooks()`). **ETag/304** :
  `fetchBooks()` revalide à chaque appel (`If-None-Match` + `cache: 'no-cache'`) et réutilise la liste en cache sur
  304 (même référence → `$patch` no-op). `clearBooksCache()` reset ce cache (tests / refresh forcé).
- `GET /api/scrap?pattern=…` → `Book[]` — recherche de métadonnées fusionnée Audible + Lizzie (`search()`).
- `POST /api/upload` (multipart) — envoi d'un ZIP + métadonnées vers 1Fichier ; **201** + Book JSON (avec `id` =
  id 1Fichier), **400** si formulaire invalide, **500** si échec persist, **502** si échec 1Fichier (`uploadBook()`,
  en **XHR** avec `onProgress`/`signal` pour le suivi de progression et l'abort).
- `GET /api/download/{id}` — **302** `Location: <URL temporaire 1Fichier>` ; **404** inconnu ; **502** fournisseur KO
  (bouton « Télécharger » des cartes).
- `GET /api/orphans` — `Table<Orphan>` (`{id, name, size, date}`) — fichiers 1Fichier non référencés ;
  `POST /api/orphans/{id}` récupère un orphelin (Book) ; `DELETE /api/orphans/{id}` supprime le fichier
  (`src/api/orphans.ts`, vue `/orphans`).
- `GET /api/intruders` — les « intrus » : `Table<Book>` — livres dont le fichier a disparu de 1Fichier ;
  `DELETE /api/intruders/{id}` supprime l'entrée en base (`src/api/intruders.ts`, vue `/intruders`).

Mapping JSON → entités (snake_case → camelCase) dans `src/api/book.ts`. Détails complets dans `SPECS.md` § 4.

### Config API

La base de toutes les requêtes API est `API_BASE_URL` (`src/api/config.ts`), alimentée par **`VITE_API_BASE_URL`** :

| Fichier | Usage | Valeur |
|---------|-------|--------|
| `.env.development` (committé) | dev par défaut | `''` → relatif, proxifié par vite vers `localhost:8000` |
| `.env.development.local` (gitignoré) | conf locale, par machine | ex. `http://localhost:8000` (appel direct, CORS) |
| `.env.production` (committé) | build prod | `''` → relatif (monolithe : même origine = API de prod) |

`.env.development.local` est le « fichier de conf local » : non committé (`*.local` dans `.gitignore`), il surcharge
`.env.development`. Ne jamais y mettre de secret.

---

## Règles pour l'agent

1. **Lire `SPECS.md` en entier avant de coder.** Ne pas diverger de la spec sans le signaler.
2. Ne pas casser le contrat API ni les types `Book`/`Saga` (utilisés par le backend et la spec).
3. Après toute modification : `npm run type-check` puis `npm run build`. Vérifier `npm run lint`.
4. Ne pas régresser le flux métier : parcourir → ajouter (fichier + métadonnées) → file d'attente → envoi.
5. Préserver le thème visuel (ambre/Deckard Cain) et le français dans l'UI.
6. Tests : **Vitest + @vue/test-utils** (décision actée, cf. `SPECS.md` § 7 — queue, form, mapping API couverts).
   Commande : `npm run test`. Config dans `vite.config.ts` (`test` bloc).

---

## État actuel (résumé — détail dans SPECS.md)

- La **file d'attente** (`stores/queue.ts` + `components/queue/FormQueue.vue`, panneau latéral dans `App.vue`) est
  **fonctionnelle** : chaque draft porte le fichier ZIP, le bouton « Envoyer » envoie via `uploadBook()`
  (`draft` → `pending` → `running` → `transferring` → `ended`, `error` en cas d'échec) et rafraîchit la bibliothèque
  après chaque succès. La file passe au suivant dès que l'**upload client** du précédent est terminé (item en
  `transferring`) : **le transfert serveur se poursuit en parallèle** de l'upload du suivant. Chaque item expose une **barre de progression** (`item.progress`, XHR `onUploadProgress`), un
  **log d'avancement** (`item.log[]` : Envoi → Transfert 1Fichier → Enregistrement → Notification Discord → Terminé)
  et un bouton **Pause/Reprendre** (abort de l'upload en cours via `AbortSignal` ; bouton dispo tant que l'item est
  `draft`/`pending`/`running`, plus possible dès l'état `transferring` (100 % client) ni sur `ended`/`error` ; un
  bouton « Pause » de file entière (`pauseAll`) met toute la file en pause sauf transferts/termines/erreurs ; les
  items laissés `pending` restent en attente jusqu'au prochain « Envoyer ») et un bouton **Réessayer** sur les items
  `error` (relance individuelle de la tentative, désactivée si un upload est en cours).
- **Pas de persistance** de la queue (un rechargement la vide).
- `DropBox` restreint la sélection/dépôt aux fichiers `.zip` (`accept=".zip"` + contrôle d'extension client avec
  message « Seuls les fichiers .zip sont acceptés. ») ; le backend valide aussi l'extension et le mime par contenu.
- Les routes `/api/upload` et `/api/download/{id}` sont **câblées** : envoi et
  bouton « Télécharger » fonctionnent bout-en-bout (suite PHPUnit verte côté backend).
- `FormSagaSelect.vue` : sélecteur de saga avec dropdown (positionnement par `getBoundingClientRect`).
