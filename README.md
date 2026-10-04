# listen

Bibliothèque personnelle de livres audio : parcourez votre bibliothèque, scrapez les métadonnées
(Audible.fr / Lizzie.audio), éditez-les et envoyez vos fichiers vers 1Fichier avec notification Discord.

## Description

Application web full-stack composée d'un backend **Symfony 7.4 / API Platform** (PHP >= 8.2) et d'un
frontend **Vue 3 + Vite + TypeScript**. En production, le build du frontend est copié dans `backend/public`
(monolithe servi par Symfony). Le thème visuel s'inspire de Diablo / Deckard Cain, l'interface est
entièrement en français.

> ⚠️ Projet personnel — usage privé, pas de contribution externe.

## Fonctionnalités

- **Bibliothèque** : vues « Sagas / Livres », regroupement par saga, recherche insensible à la casse et aux accents.
- **Ajout d'un livre** : dépôt du ZIP → recherche de métadonnées (Audible + Lizzie fusionnées) → édition → preview → envoi.
- **File d'attente** : envois séquentiels en arrière-plan, indépendants de la navigation — progression par item,
  pause/reprendre, réessai, réordonnancement.
- **Téléchargement** : lien 302 vers l'URL temporaire 1Fichier.
- **Maintenance 1Fichier** : pages « fichiers orphelins » (non référencés) et « intrus » (livres sans fichier).
- **Notifications Discord** : nouveau livre disponible + erreurs runtime (webhooks optionnels).

## Prérequis

- PHP >= 8.2 (8.4 recommandé, cf. `backend/.php-version`) avec l'extension **intl**
- Composer
- Node.js 24 (via nvm) + npm
- Docker (PostgreSQL local via docker compose)
- Symfony CLI (`symfony serve`)

## Installation

```bash
git clone git@github.com:ilgazil/listen.git
cd listen
make install        # composer install (backend) + npm install (frontend)
make db-up          # PostgreSQL local (docker compose)
cp backend/.env backend/.env.local   # puis renseigner les variables (voir Configuration)
make db-create      # création de la base + migrations
```

En dev, lancer deux terminaux :

```bash
make backend        # API Symfony   → http://localhost:8000
make frontend       # Vite          → http://localhost:5173 (proxy /api → localhost:8000)
```

> `make backend` charge automatiquement `backend/.php-local/` (ini de dev : 1G mémoire / uploads 1G,
> requis pour les gros ZIP — le PHP de dev est limité à 128M / 2M par défaut).

## Commandes

Toutes les commandes passent par le `Makefile` (`make help` liste tout) :

| Cible | Rôle |
|-------|------|
| `make install` | installe les dépendances (backend + frontend) |
| `make backend` / `make frontend` | serveurs de dev (Symfony :8000 / Vite :5173) |
| `make db-up` / `make db-create` / `make db-migrate` | base locale (docker, création, migrations) |
| `make test` / `make test-backend` / `make test-frontend` | tests (PHPUnit / Vitest) |
| `make lint` / `make format` | lint (container+yaml / eslint) et format (prettier) |
| `make build` | type-check + build frontend dans `frontend/dist` |
| `make check` | lint + tests + build (pré-déploiement) |
| `make deploy-dry` / `make deploy` | déploiement (simulation / réel) |
| `make ssh-key` / `make ssh-copy` | clé SSH de déploiement dédiée (une seule fois) |

## Configuration

**Jamais de valeurs en dur ni de secrets committés** — tout passe par des fichiers locaux gitignorés.

### Backend — `backend/.env.local` (à partir de `backend/.env.example`)

| Variable | Description |
|----------|-------------|
| `DATABASE_URL` | PostgreSQL en dev (docker compose), MariaDB en prod |
| `APP_SECRET` | secret Symfony |
| `API_1FICHIER_TOKEN` | token API 1Fichier |
| `API_1FICHIER_APP_FOLDER_ID` | id du dossier applicatif 1Fichier |
| `DISCORD_NEW_BOOK_WEBHOOK` | (optionnel) webhook « nouveau livre disponible » |
| `DISCORD_SENTRY_WEBHOOK` | (optionnel) webhook des erreurs runtime |
| `APP_BASE_URL` | (optionnel) surcharge l'URL des liens Discord (sinon dérivée de la requête) |
| `CORS_ALLOW_ORIGIN` | origins autorisées (localhost par défaut) |

### Frontend — `frontend/.env.development.local`

| Variable | Description |
|----------|-------------|
| `VITE_API_BASE_URL` | (optionnel) base de l'API ; vide = relatif, proxifié par Vite vers `localhost:8000` |

### Déploiement — `.deploy.env` (à partir de `.deploy.env.example`)

| Variable | Description |
|----------|-------------|
| `PROD_HOST` / `PROD_SSH_USER` / `PROD_PORT` | accès SSH au serveur |
| `PROD_PATH` | chemin du vhost côté serveur |
| `PROD_URL` | (optionnel) URL publique, affichée dans le récapitulatif de déploiement |
| `PROD_PHP` | binaire PHP CLI du serveur (doit avoir intl) |
| `PROD_USE_COMPOSER` | `1` = composer install sur le serveur, `0` = vendor envoyé tel quel |
| `DEPLOY_SSH_KEY` | clé privée dédiée au déploiement |

## API (contrat)

| Endpoint | Rôle |
|----------|------|
| `GET /api/books` (+ `/{id}`) | bibliothèque (API Platform, ETag/304) |
| `GET /api/scrap?pattern=…` | recherche de métadonnées fusionnée Audible + Lizzie |
| `POST /api/upload` | envoi d'un ZIP + métadonnées → 1Fichier → `Book` → notif Discord |
| `GET /api/download/{id}` | 302 vers l'URL temporaire 1Fichier |
| `GET/POST/DELETE /api/orphans…` | fichiers 1Fichier non référencés |
| `GET/DELETE /api/intruders…` | livres dont le fichier a disparu |

Le contrat détaillé (formes JSON, statuts HTTP) est dans [`backend/SPECS.md`](backend/SPECS.md).

## Tests

```bash
make test            # backend (PHPUnit, 1Fichier mocké) + frontend (Vitest)
make lint            # lint container/yaml + eslint
make check           # lint + tests + build
```

## Déploiement

1. `make ssh-key` puis `make ssh-copy` (une seule fois — dernière fois qu'un mot de passe est demandé).
2. `cp .deploy.env.example .deploy.env` puis renseigner les accès.
3. `make deploy-dry` pour simuler, `make deploy` pour déployer (rsync des sources + build frontend, cache Symfony).

Le déploiement exige un **arbre git propre** ; les migrations Doctrine sont appliquées manuellement sur le serveur
(`php bin/console doctrine:migrations:migrate`).

## Documentation

- [`backend/AGENTS.md`](backend/AGENTS.md) — mode d'emploi opérationnel du backend · [`backend/SPECS.md`](backend/SPECS.md) — contrat API & décisions
- [`frontend/AGENTS.md`](frontend/AGENTS.md) — mode d'emploi opérationnel du frontend · [`frontend/SPECS.md`](frontend/SPECS.md) — comportement & fonctionnalités cibles

## Licence

Projet privé — tous droits réservés.
