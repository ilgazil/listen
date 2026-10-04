# AGENTS.md — Backend (Symfony / API Platform)

Backend de **listen** : bibliothèque personnelle de livres audio (sources : Audible.fr et Lizzie.audio).
Il expose une API REST (métadonnées via scraping, upload/stockage 1Fichier, notification Discord) et sert aussi
le frontend buildé (application « monolithe ») depuis `public/`.

---

## Vue d'ensemble

- **Stack** : Symfony 7.4, PHP >= 8.2, API Platform (Symfony), Doctrine ORM 3, Nelmio CORS.
- **Base de données** : PostgreSQL en dev (docker compose), MariaDB en prod (Infomaniak).
- **Rôle** : 1 entité (`Book`), 2 contrôleurs (`ApiController` : upload/download 1Fichier + orphelins/intrus +
  `ScrapController` : scraping) + les routes API Platform auto-générées.
- **Frontend** : Vue 3 (dossier `../frontend`). En dev il tourne sur `http://localhost:5173` et proxy `/api` vers `localhost:8000`.
  En prod, le build frontend (`frontend/dist`) est copié dans `public/` et servi par Symfony.
- **DocRoot obligatoire** : le vhost doit pointer sur `public/` (Symphony monolithe). Depuis l'admin Infomaniak :
  dossier du site = valeur de `PROD_PATH` (`.deploy.env` côté serveur), docroot = `public`. Un vhost pointant sur la racine du projet sert
  `composer.json`/`.env*…` (fuite) et renvoie 403 (pas d'index). `public/.htaccess` route `/api/*` vers `index.php`
  et les autres routes vers `index.html` (SPA) — ne pas le retirer du rsync.

---

## Commandes utiles

> Toutes aussi dispo via `make backend` / `make test`… à la racine du repo.

```bash
# Installer les dépendances
composer install

# Config locale (ne pas committer)
cp .env .env.local

# Base de données
docker compose up -d                                    # PostgreSQL local
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate             # (1 migration commitée : Version20260808000001)

# Serveur de dev
symfony serve                                           # http://localhost:8000
# Le fichier .php-version épingle PHP 8.4. intl requis par Book::getFilename() (Transliterator).
# Homebrew "php" 8.4 officiel est buildé SANS intl → utiliser le tap shivammathur/php :
#   brew install shivammathur/php/php@8.4 && brew link --overwrite --force shivammathur/php/php@8.4
# Uploads en local : le PHP de dev limite à memory_limit=128M / upload_max_filesize=2M / post_max_size=8M par
# défaut, ce qui fait échouer les gros ZIP (`symfony/http-foundation` : Allowed memory size exhausted). Le PHP de
# prod est déjà calibré, mais pas un laptop neuf. Le dossier committé `.php-local/` contient un ini de dev
# (1G de mémoire, uploads 1G) chargé automatiquement via la variable d'environnement PHP_INI_SCAN_DIR :
#   PHP_INI_SCAN_DIR=":$(pwd)/.php-local" symfony serve
# (`make backend` le fait déjà.) Vérifier : php -r 'var_dump(ini_get("memory_limit"), ini_get("upload_max_filesize"));'

# Tests
php bin/phpunit

# Utilitaires
php bin/console debug:router
php bin/console cache:clear
```

---

## Structure

```
src/
├── Command/
│   ├── ParseBookCommand.php          # app:scrap-book (re-scrape un livre)
│   └── BookBatchCommand.php          # app:batch (nettoyage en lot, normalise tome)
├── Controller/
│   ├── ApiController.php             # POST /api/upload + GET /api/download/{id}
│   └── ScrapController.php           # GET /api/scrap (recherche fusionnée Audible + Lizzie)
├── Entity/
│   └── Book.php                     # unique entité Doctrine, API Platform
├── EventListener/
│   └── BookCollectionCacheListener.php # ETag/304 + Cache-Control de GET /api/books (lib)
├── Repository/
│   └── BookRepository.php           # libraryVersionHash(): md5 des lignes de `book` (SQL brut)
└── Service/
    ├── Api/
    │   └── UnFichierApi.php         # upload + token de téléchargement 1Fichier
    ├── Notification/
    │   ├── Notifier.php             # interface
    │   └── Discord/                 # webhook + message rich embed
    ├── Scraping/
    │   ├── ScraperInterface.php
    │   ├── ScrapperFactory.php      # choix du scraper selon $book->scraper
    │   ├── Cache.php                # trait cache regex (mémoïsation par parser)
    │   ├── Audible/                 # AudibleScraper, AudibleSearchParser, AudibleBookParser
    │   └── Lizzie/                  # LizzieScraper, LizzieCache, LizzieItem
    └── Uploading/
        └── LocalFileUploader.php    # dépose un UploadedFile dans public/uploads/files
```

---

## Domaine — Entité `Book`

| Champ        | Type / longueur   | Notes |
|--------------|-------------------|-------|
| `id`         | string, 20, nullable | identifiant custom (non auto-généré). Côté métier : ID de téléchargement 1Fichier |
| `scraper`    | string, 50, nullable | `audible` \| `lizzie` |
| `scrap_id`   | string, 50, nullable | identifiant côté source (slug Lizzie / ASIN Audible) |
| `title`      | string, 255        | requis |
| `cover`      | string, 255        | URL de couverture |
| `author`     | string, 255        | |
| `narrators`  | SIMPLE_ARRAY       | liste de chaînes |
| `runtime`    | string, 15, nullable | format `H:i` (`00:00` = inconnu) |
| `ratings`    | float, nullable    | note Audible (ex. `4.6`) |
| `saga`       | string, 255        | **stockée en simple colonne string** (pas de relation ni entité Saga) |
| `tome`       | string, 40, nullable | numéro de tome (`VARCHAR(40)` depuis la migration, ex. `1.5`) |

**Serialisation** : `#[ApiResource]` sans contexte d'API Platform au niveau ressource ; les propriétés portent
`#[Groups(['book:read'])]`. `ScrapController` normalise explicitement avec `groups: ['book:read']`,
`iri: false`, `skip_null_values: true`, `api_platform_disable: true`.

---

## API

### Routes livrées

- `GET /api/books?pagination=false` — liste des livres (API Platform). Utilisé par `frontend/src/api/book.ts::fetchBooks()`.
  **Cache HTTP** : `EventListener/BookCollectionCacheListener` calcule un ETag (hash SQL de la bibliothèque +
  URI) à chaque requête ; **304** (corps vide) si `If-None-Match` correspond (court-circuit via contrôleur
  jetable, avant toute pipeline API Platform), sinon **200** + `ETag` + `Cache-Control: private, max-age=0, must-revalidate`.
- `GET /api/books/{id}` — détail (API Platform).
- `GET /api/scrap?pattern=...` — recherche de métadonnées, **fusion Lizzie + Audible**.
  - `pattern` est normalisé (`strtolower`, non-word → espace, espaces multiples → un seul).
  - Lizzie : recherche substring (titre OU auteur, insensible à la casse) dans le catalogue en cache, max 20.
  - Audible : scrap de la page de recherche audible.fr.
  - **Filtre** : les résultats Audible sans narrateur sont écartés (dans `AudibleSearchParser::getBooks()`).
- `POST /api/upload` (multipart/form-data) — champs `author, title, cover, saga, tome, narrators, runtime, ratings,
  scraper, scrap_id` + `file` (ZIP obligatoire : **extension `.zip`** + mimes par contenu `application/zip`,
  `application/octet-stream`, `application/x-zip-compressed`, `multipart/x-zip`). Flux : upload local
  (`LocalFileUploader`) → upload 1Fichier (`UnFichierApi::upload`) → `book.id` = id de téléchargement 1Fichier →
  suppression du fichier local → persist → notification Discord. `narrators` est un tableau sur l'API (le champ
  multipart reste une chaîne CSV parsée en tableau). Réponses : **201** en succès (Book sérialisé), **400** si
  formulaire invalide, **500** si échec persist, **502** si échec 1Fichier.
- `GET /api/download/{id}` — **302** `Location: <URL temporaire 1Fichier>` ; **404** si livre inconnu, **502** si le
  fournisseur ne renvoie pas d'URL.
- `GET /api/orphans` — fichiers 1Fichier non référencés (liste `{id, name, size, date}`) ; `POST /api/orphans/{id}`
  crée le `Book` ; `DELETE /api/orphans/{id}` supprime le fichier — cf. `SPECS.md` § 4.3–4.5.
- `GET /api/intruders` — les « intrus » : `Book` dont le fichier a disparu de 1Fichier (liste `Book JSON`) ;
  `DELETE /api/intruders/{id}` supprime l'entrée en base (409 si le fichier existe toujours) — cf. `SPECS.md` § 4.6–4.7.

### Commandes CLI
- `app:scrap-book <ids...>` — re-scrape un livre depuis son scraper (Audible/Lizzie), propose les champs modifiés (confirmation interactive).
- `app:batch` — nettoyage en lot (ex. normalisation de `tome` : extrait `[\d.]+`).

---

## Services

### Scraping
- `ScraperInterface` : `search(string): array`, `get(string): ?Book`.
- **Audible** (`AudibleScraper`) : `rawGet` sur `audible.fr` + **parsing regex du HTML** (`AudibleSearchParser`,
  `AudibleBookParser`). Extrait : id, cover, title, saga/tome (`computeSagaMetadata()`), author, narrators, runtime, ratings.
  Le parsing est fragile par nature (dépend du markup Audible).
- **Lizzie** (`LizzieScraper`) : utilise `LizzieCache` — un JSON du catalogue (`lizzie-api.staytuned.io/v1/catalog`)
  mis en cache **une fois par jour** dans `public/cache/lizzie/cache_YYYY-MM-DD.json`. Extrait : title, author, cover,
  runtime, narrators (un seul champ). Pas de ratings ni saga/tome.
- `ScrapperFactory::fromBook(Book)` — résout le scraper par nom (`audible`/`lizzie`), sinon `InvalidArgumentException`.

### Upload local
- `LocalFileUploader` : `upload(UploadedFile): string` → dépose dans `%upload_directory%` (`public/uploads/files`),
  nom = slug + `-` + uniqid + extension.

### 1Fichier (`UnFichierApi`)
- `upload($path, $mimeType, $name): string` : obtient un serveur d'upload, envoie le fichier, puis **polls**
  `ls.cgi` jusqu'à trouver le fichier et en extraire l'ID de téléchargement (boucle `do/while` avec `sleep(1)`).
- `download($id): string` : `get_token.cgi` → URL de téléchargement temporaire.
- `getOrphans($existingIds)` / `getStoredIds()` : listing paginé `ls.cgi` du dossier applicatif — fichiers non
  référencés par un `Book`, inverse : hash d'accès des fichiers présents.
- Nécessite `API_1FICHIER_TOKEN` et `API_1FICHIER_APP_FOLDER_ID`.

### Notification Discord
- `Notifier::available(Book)` ; implémentation `DiscordWebhook` → `jsonPost` sur `DISCORD_NEW_BOOK_WEBHOOK`.
- `Notifier::error(Throwable)` ; implémentation `DiscordWebhook::error(Throwable)` → `jsonPost` sur
  `DISCORD_SENTRY_WEBHOOK` (embed `ErrorMessage`, couleur rouge) ; **no-op si la variable est vide/absente**.
  `ErrorMessage` accepte `Throwable|string` et tronque la description à 2000 caractères.
- **Filtre `4xx` :** `DiscordWebhookHandler` (Monolog, canaux `app` + `request`, niveau `error`) poste tous les
  enregistrements ≥ `error` SAUF les `HttpExceptionInterface` de statut < 500 (404/405… légitimes non notifiés).
  Les exceptions non attrapées arrivent par le canal `request` ; les erreurs attrapées (`$this->logger->error()`…)
  par le canal `app`. Les éventuels retours 502 sans log sont notifiés explicitement (`ApiController::download`).
- `AvailableBookMessage` : embed rich « Nouveau livre disponible » (couleur `f59e0b`), champs Saga/Tome, Durée,
  Évaluation, image couverture, lien `{base_url}/api/download/{id}` (`DiscordWebhook::appUrl()`).
- **Aucune URL de site en dur** : les liens des notifications sont construits depuis la **requête courante**
  (`RequestStack` → `BaseUrl::from($request->getSchemeAndHttpHost())`) et surchargés par `APP_BASE_URL` si définie.

---

## Configuration & variables d'environnement

`config/services.yaml` :
```yaml
parameters:
    upload_directory: '%kernel.project_dir%/public/uploads/files'
    lizzie_cache: '%kernel.project_dir%/public/cache/lizzie'
```

Variables attendues (noms uniquement — **ne jamais committer de valeurs** ; `.env.dev` contient des credentials de prod) :
- `DATABASE_URL`
- `API_1FICHIER_TOKEN`
- `API_1FICHIER_APP_FOLDER_ID`
- `DISCORD_NEW_BOOK_WEBHOOK` (notification « nouveau livre disponible »)
- `DISCORD_SENTRY_WEBHOOK` (erreurs runtime — `DiscordWebhook::error()` + handler Monolog `app`/`request` ; no-op si vide)
- `APP_BASE_URL` (optionnel — surcharge l'URL des liens Discord, dérivée de la requête par défaut ; à définir en
  prod derrière un reverse proxy qui force `http`, et hors contexte requête)
- `CORS_ALLOW_ORIGIN` (`^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$` par défaut)
- `MESSENGER_TRANSPORT_DSN` (doctrine par défaut, non utilisé aujourd'hui)
- `APP_SECRET`

---

## Conventions

- PHP >= 8.2 : propriétés typées, `readonly class` quand possible, setters fluides (`return $this`).
- **Langues** : code en anglais (identifiants, noms de fichiers, messages du contrat API), **commentaires en français**,
  docs en français.
- Style existant : 4 espaces, `null`/`''` pour les absences, docblocks minimalistes.
- **Pas de formateur / analyseur statique configuré** (ni php-cs-fixer, ni phpstan, ni rector) → garder le style en place.
- Ne pas logger les tokens/secrets (`Psr\Log\LoggerInterface` est injecté dans `UnFichierApi` pour les logs de debug).
- Tests fonctionnels : `php bin/phpunit` (PHPUnit 12 via `phpunit.xml.dist`, `tests/bootstrap.php`). En test, certains services
  (`UnFichierApi`, `DiscordWebhook`, scrapers) sont **publics** via `config/services_test.yaml` pour pouvoir être mockés
  avec `TestContainer::set()` (les services privés sont inlinés dans les fabriques du container et ne peuvent pas être
  remplacés).

---

## Pièges connus

- Parsing Audible : regex dépendantes du markup HTML, à isoler et traiter comme fragile.
- `UnFichierApi::upload()` est **bloquant** (polling 1s jusqu'à indexation du fichier) → envisager Messenger/async
  si la file d'envoi doit traiter plusieurs livres.
- `UnFichierApi::getDownloadId()` est **borné** (`MAX_INDEXING_ATTEMPTS` essais max, cf. `backend/SPECS.md` § 6)
  et renvoie **502** à l'épuisement.
- `LizzieCache` écrit dans `public/cache/lizzie` : à whitelister/exclure dans les logs et backups.
- `Book.tome` est en `VARCHAR(40)` (migration faite) — ex. `1.5` passe.
- Le champ `id` n'est pas auto-généré : les livres scrapés sont renvoyés sans `id` (`createBook()` n'appelle jamais `setId()`).
- `UploadedFile::getMimeType()` redevine le type depuis le fichier et jette une fois le fichier déplacé par
  `LocalFileUploader` → utiliser `getClientMimeType()` (`ApiController::upload`).
- `tests/Fixtures/audible-search.html` (échantillon de la page de recherche Audible) est **gitignoré**
  (`/tests/Fixtures/*.html`) ; le test d'intégration `AudibleSearchParserTest::testParsesFixture()` se
  `markTestSkipped()` s'il est absent.
