# 📦 Backend — Symfony 7.4 / API Platform

Backend de **Listen**, bibliothèque de livres audio. API REST consommée par le frontend Vue.js.

## Stack

- PHP 8.4 (épingle dans `.php-version`, intl requis — tap Homebrew shivammathur/php, cf. `AGENTS.md`)
- Symfony 7.4, API Platform
- Doctrine DBAL — PostgreSQL en dev (docker), MariaDB 10.6 en prod (Infomaniak)
- Nelmio CORS, PHPUnit (76 tests)

## Mise en route

```bash
composer install
cp .env .env.local            # override local (ne pas committer)

docker compose up -d
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

symfony serve                 # http://localhost:8000
```

## API

- `GET  /api/books` — liste (API Platform, `?pagination=false` pour tout)
- `GET  /api/books/{id}` — détail
- `PUT  /api/books/{id}` — mise à jour
- `GET  /api/scrap` — recherche scraper (Audible/Lizzie)
- `POST /api/upload` — upload + transfert 1Fichier
- `GET  /api/download/{id}` — téléchargement

## Commandes

```bash
php bin/console app:scrap-book   # maj infos d'un livre depuis son scraper
php bin/console app:batch        # maj en masse (fonction personnalisée)
php bin/console debug:router
```

## Tests & qualité

```bash
php bin/phpunit
php bin/console lint:container
php bin/console lint:yaml config
composer audit
```

> ⚠️ Le cache des scrapers (`public/cache/lizzie/*.json`) est un artefact runtime — non versionné.

Toutes ces commandes sont aussi accessibles via le `Makefile` à la racine du repo (`make backend`, `make test`, `make db-up`…).
Licence : propriétaire. Détails d'architecture : `AGENTS.md` et `SPECS.md`.