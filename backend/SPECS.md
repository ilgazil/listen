# SPECS — Backend Symfony / API Platform

> **Statut : implémenté (08/08/2026).**
> `POST /api/upload`, `GET /api/download/{id}` et la gestion des fichiers orphelins/intrus (§ 4) sont couverts par
> les tests PHPUnit (§ 7). L'élément différé (système de release) reste listé en fin de § 6.
> Ce document reste la référence du contrat API.
> Le `AGENTS.md` du backend est le mode d'emploi opérationnel ; ce fichier décrit le *quoi*.

---

## 1. Contexte

Backend d'une bibliothèque personnelle de livres audio (sources : Audible.fr et Lizzie.audio). Le backend (`backend/`) expose :

- `GET /api/books` et `GET /api/books/{id}` via API Platform (auto-générés) ;
- `GET /api/scrap` (`ScrapController`) ;
- le flux d'envoi : `POST /api/upload` / `GET /api/download/{id}` + gestion des orphelins/intrus, avec les services
  associés (`UnFichierApi`, `LocalFileUploader`, scrapers Audible/Lizzie, notif Discord).

Le contrat API est décrit ci-dessous et aligné avec `frontend/SPECS.md` § 4.

---

## 2. Exigences produit (non négociables)

- [ ] **Contrat API stable** : endpoints, formes JSON et statuts HTTP alignés sur `frontend/SPECS.md` § 4.
- [ ] **`narrators` est un tableau dans toutes les réponses** (décision actée). Le champ multipart reste une chaîne
      CSV, convertie en tableau côté backend.
- [ ] **`saga` en simple colonne `VARCHAR(255)`** : pas d'entité Saga, pas de normalisation automatique (le front
      envoie la valeur telle quelle).
- [ ] **`tome` en `VARCHAR(40)`** (ex. `1.5`).
- [ ] **API publique sans authentification** (comme aujourd'hui, `security.yaml` sans `access_control`).
- [ ] **Notification Discord à chaque livre ajouté** (`Notifier::available`) — lien du message construit depuis
      la requête courante (ou `APP_BASE_URL`) : `{base_url}/api/download/{id}` (aucune URL en dur).
- [ ] **Le fichier local est supprimé après l'upload 1Fichier** — aucun stockage durable du ZIP côté serveur.
- [ ] **Erreurs exposées en JSON** et statuts HTTP précis (pas de texte brut, pas de 500 générique).

---

## 3. Routes livrées (état actuel — à ne pas casser)

### 3.1 `GET /api/books?pagination=false`

Route API Platform auto-générée sur `Book` (`#[ApiResource]`, préfixe `/api`).

| Élément | Valeur |
|---------|--------|
| Réponse | `{ "member": [ Book JSON ] }` (collection) |
| `pagination=false` | désactive la pagination (requis par le front) |
| Book JSON | `id, scraper, scrap_id, title, cover, author, narrators, runtime, ratings, saga, tome` (snake_case, `narrators` = tableau) |

### 3.2 `GET /api/books/{id}`

Route API Platform auto-générée (item). `id` = chaîne (ID de téléchargement 1Fichier).

| Cas | Réponse |
|-----|---------|
| Livre trouvé | 200 + Book JSON |
| Inconnu | 404 (corps API Platform `{"type":".../not_found","title":"Not Found",...}`) |

⚠️ **`id` est nullable et non auto-généré** : les livres scrapés (réponse `/api/scrap`) n'ont pas d'`id`.

### 3.3 `GET /api/scrap?pattern=…`

`ScrapController::__invoke` — recherche de métadonnées **fusionnée Lizzie + Audible**.

| Élément | Valeur |
|---------|--------|
| Query | `pattern` (obligatoire, sinon `[]`) |
| Réponse | `[ Book JSON ]` (tableau, sérialisé avec `groups: ['book:read']`, `iri: false`, `skip_null_values: true`, `api_platform_disable: true`) |
| Lizzie | recherche substring (titre OU auteur, insensible à la casse), max 20 résultats |
| Audible | scrap page audible.fr ; **les résultats sans narrateur sont écartés** (`AudibleSearchParser::getBooks()`) |
| Ordre | Lizzie d'abord, puis Audible |

Bug mineur à corriger au passage : `ScrapController` lit `$request->query->get('pattern')` pour la validation mais
`$request->get('pattern')` pour la recherche → unifier sur `$request->query->get('pattern')`.

### 3.4 Restrictions (à ne pas casser)

- `POST /api/books` (et PUT/PATCH/DELETE) sont auto-générés par API Platform mais **jamais utilisés** →
  **désactivés** (seuls `Get` + `GetCollection` exposés dans `src/Entity/Book.php`).
- Tests fonctionnels PHPUnit (`tests/Controller/`, 1Fichier mocké).

---

## 4. Routes implémentées

### 4.1 `POST /api/upload`

**Objectif** : recevoir un ZIP + métadonnées, l'envoyer vers 1Fichier, créer le `Book`, notifier Discord.

Contrat — **multipart/form-data**, noms de champs nus (préfixe de formulaire `''`), CSRF désactivé.

#### Requête — `multipart/form-data`

| Champ | Type | Requis | Notes |
|-------|------|--------|-------|
| `file` | fichier ZIP | ✅ | extension `.zip` + mimes (contenu) : `application/zip`, `application/octet-stream`, `application/x-zip-compressed`, `multipart/x-zip` |
| `title` | string | ✅ | obligatoire |
| `author` | string | non | vide autorisé |
| `cover` | string (URL) | non | |
| `saga` | string | non | envoyé tel quel (pas de normalisation) |
| `tome` | string | non | `VARCHAR(40)` |
| `narrators` | string (CSV) | non | ex. `"Julien Rochefort, Marie Duplex"` → **tableau** en base/API |
| `runtime` | string | non | format `H:i` (`00:00` = inconnu) |
| `ratings` | string | non | ex. `"4.6"` → **float** en base/API |
| `scraper` | string | non | `audible` \| `lizzie` |
| `scrap_id` | string | non | ASIN Audible / slug Lizzie |

#### Flux (pipeline)

1. Lire le corps multipart et **valider** (fichier ZIP obligatoire + champs texte).
2. `LocalFileUploader::upload(file)` → dépôt local dans `%upload_directory%`.
3. `UnFichierApi::upload(path, mimeType, filename)` → **bloquant** (polling `ls.cgi`, 1s/essai) → retourne l'ID de
   téléchargement 1Fichier.
4. `book.id = <id 1Fichier>`.
5. Supprimer le fichier local (`unlink(realpath($path))`).
6. `persist` + `flush` du `Book`.
7. `Notifier::available(book)` → webhook Discord (embed « Nouveau livre disponible »).
8. Réponse **201** + Book JSON (format API Platform, mêmes champs que `GET /api/books`).

`filename` 1Fichier (`Book::getFilename()`) :
`{auteur} - {saga} - {tome} - {titre}` translittéré (Any-Latin → ASCII → NFD, retrait des accents), non-word → espace,
espaces → `-`, minuscules, suffixe `.zip`.

#### Réponses

| Statut | Corps | Cas |
|--------|-------|-----|
| **201** | Book JSON | succès complet |
| **400** | `{"error": "<message>"}` | formulaire invalide / fichier manquant / mauvais mime |
| **500** | `{"error": ...}` | échec persist DB (après upload 1Fichier) |
| **502** | `{"error": ...}` | échec 1Fichier (upload ou récupération d'ID) |

⚠️ `UnFichierApi::getDownloadId()` est **borné** (`MAX_INDEXING_ATTEMPTS` essais max) et renvoie **502** à
l'épuisement, sinon la requête ne se terminerait jamais.

#### Implémentation — points d'attention

- [x] `src/Form/BookType.php` : `getBlockPrefix() === ''`, `csrf_protection => false`,
      `FileType` + contrainte mimes sur `file`, `data_class` = `Book`.
- [x] **`narrators` (array) et `ratings` (float) ne se lient pas avec `TextType`** (l'entité actuelle est typée).
      Champs `narrators`/`ratings` **non mappés** (`'mapped' => false`), puis conversion manuelle après
      `handleRequest` (`explode` CSV→array, `(float)`).
- [x] `Book::getFilename()` : nom de fichier 1Fichier.
- [x] Sérialisation de la réponse : même normalisation que `ScrapController` (`groups: ['book:read']`,
      `iri: false`, `skip_null_values: true`, `api_platform_disable: true`) → `narrators` en tableau,
      `ratings` en float.

### 4.2 `GET /api/download/{id}`

**Objectif** : rediriger vers l'URL de téléchargement temporaire 1Fichier.

#### Flux

1. `find($id)` sur `Book`.
2. `UnFichierApi::download(id)` → `get_token.cgi` → URL temporaire.
3. Redirection **302** `Location: <URL temporaire>`.

#### Réponses

| Statut | Corps | Cas |
|--------|-------|-----|
| **302** | en-tête `Location: <URL 1Fichier>` | succès |
| **404** | JSON | livre inconnu |
| **502** | JSON | le fournisseur ne renvoie pas d'URL (`UnFichierApi::download()` retourne `''`) |

### 4.3 `GET /api/orphans`

**Objectif** : lister les fichiers présents sur 1Fichier qui ne correspondent à **aucun** `Book` de la
bibliothèque (les « fichiers orphelins »).

#### Flux

1. `BookRepository::findIds()` → ensemble des `id` persistés.
2. `UnFichierApi::getOrphans($existingIds)` → liste paginée (`ls.cgi`, `LISTING_PAGE_SIZE=100`,
   `MAX_LISTING_PAGES=100`) des fichiers du dossier (`API_1FICHIER_APP_FOLDER_ID`) dont le **hash d'accès**
   (dernier segment du filename) n'est pas dans l'ensemble.

#### Réponses

| Statut | Corps | Cas |
|--------|-------|-----|
| **200** | `[ { "id", "name", "size", "date" }, … ]` | succès (liste vide = `[]`) |
| **502** | `{"error": ...}` | le fournisseur échoue au listing (`RuntimeException` de `UnFichierApi`) |

L'`id` d'un orphelin est le hash d'accès 1Fichier (le futur `Book.id` si le livre est « récupéré »).

### 4.4 `POST /api/orphans/{id}`

**Objectif** : créer un `Book` pour un fichier orphelin (le « récupérer »). Corps = même contrat que
`update()` (`applyPayload` : `scraper, scrap_id, title, cover, author, narrators, runtime, ratings, saga, tome`,
adapté du formulaire upload).

#### Flux

1. `isOrphanFile($id)` : le fichier est bien listé comme orphelin (pas un fichier de livre vivant) → sinon **404**.
2. **409** si un `Book` porte déjà cet `id` (fichier déjà enregistré).
3. Validation du titre (`non-blank`) → **400** `{"error": "Le titre est requis."}`.
4. Persist `Book` (`id` = id orphelin), **pas de notification Discord** (décision § 6).

#### Réponses

| Statut | Corps | Cas |
|--------|-------|-----|
| **201** | Book JSON | livre créé |
| **400** | `{"error": ...}` | JSON invalide / titre manquant |
| **404** | `{"error": ...}` | id inconnu (pas un orphelin) |
| **409** | `{"error": ...}` | un livre avec cet `id` existe déjà |
| **502** | `{"error": ...}` | le listing 1Fichier échoue (impossible de vérifier l'orphelinat) |

### 4.5 `DELETE /api/orphans/{id}`

**Objectif** : supprimer définitivement le fichier orphelin de 1Fichier (`rm.cgi`).

#### Flux

1. `isOrphanFile($id)` (sécurité : on ne peut **pas** supprimer le fichier d'un livre vivant) → sinon **404**.
2. `UnFichierApi::remove($id)` → si le fournisseur échoue, **502**.

#### Réponses

| Statut | Corps | Cas |
|--------|-------|-----|
| **204** | vide | fichier supprimé |
| **404** | `{"error": ...}` | id inconnu (pas un orphelin) |
| **502** | `{"error": ...}` | échec de suppression 1Fichier (ou vérification du listing) |

### 4.6 `GET /api/intruders`

**Objectif** : lister les `Book` de la bibliothèque dont le fichier a **disparu de 1Fichier** (l'« intrus » :
l'inverse d'un orphelin — l'entrée en base existe mais aucun fichier ne lui correspond dans le dossier applicatif).

#### Flux

1. `BookRepository::findAll()` → tous les livres persistés.
2. `UnFichierApi::getStoredIds()` → ensemble des hash d'accès des fichiers du dossier
   (`API_1FICHIER_APP_FOLDER_ID`, même pagination que § 4.3).
3. Filtre : livres dont `id` est non-null **et** absent de l'ensemble.

#### Réponses

| Statut | Corps | Cas |
|--------|-------|-----|
| **200** | `[ Book JSON, … ]` | succès (liste vide = `[]`) |
| **502** | `{"error": ...}` | le listing 1Fichier échoue (`RuntimeException` de `UnFichierApi`) |

### 4.7 `DELETE /api/intruders/{id}`

**Objectif** : supprimer l'entrée en base d'un livre dont le fichier a disparu de 1Fichier.

#### Flux

1. `find($id)` sur `Book` → sinon **404**.
2. `getStoredIds()` : si l'`id` est **présent** dans le listing → **409** (le fichier existe toujours, on ne
   supprime pas l'entrée d'un livre vivant).
3. `remove` + `flush` de l'entité. **Aucun appel 1Fichier** (le fichier n'existe pas).

#### Réponses

| Statut | Corps | Cas |
|--------|-------|-----|
| **204** | vide | entrée supprimée |
| **404** | `{"error": ...}` | livre inconnu |
| **409** | `{"error": ...}` | le fichier est encore présent sur 1Fichier |
| **502** | `{"error": ...}` | le listing 1Fichier échoue (vérification impossible) |

---

## 5. Schéma de données

### Entité `Book`

| Champ | Type | Notes |
|-------|------|-------|
| `id` | string, 20, nullable | = id de téléchargement 1Fichier |
| `tome` | `VARCHAR(40)`, nullable | ex. `1.5` |
| `narrators` | `SIMPLE_ARRAY` | liste de chaînes |
| `saga` | `VARCHAR(255)` | sans normalisation |
| `ratings` | float, nullable | |
| `getFilename()` | méthode | nom de fichier 1Fichier (`auteur - saga - tome - titre` translittéré, `.zip`) |

### Migrations

- `migrations/Version20260808000001.php` : extension `tome` en `VARCHAR(40)` (compatible PostgreSQL / MariaDB,
  branches `addSql` distinctes par plateforme).
- **À appliquer manuellement en prod** (pas d'exécution automatique au déploiement) :
  `php bin/console doctrine:migrations:migrate`.

---

## 6. Décisions actées

- [x] **Upload : formulaire Symfony** (`BookType`) — pas de parsing manuel (§ 4.1).
- [x] **`narrators`/`ratings`** : champs non mappés + conversion manuelle (`explode` CSV→array, `(float)`).
- [x] **Validation minimale** : `title` requis + `file` ZIP obligatoire.
- [x] **Format des erreurs** : `{"error": "<message>"}` partout (400/500/502).
- [x] **Boucle infinie 1Fichier** : borner `getDownloadId()` (max essais + timeout) → **502**.
- [x] **API Platform** : désactiver `POST/PUT/PATCH/DELETE` sur `/api/books` (n'exposer que GET collection + GET item).
- [x] **Commandes CLI** (`app:scrap-book`, `app:batch`) : conservées (usage debug, tant qu'aucun test e2e).
- [x] **Tests** : écrire des tests fonctionnels PHPUnit (upload/download) avec 1Fichier **mocké** (pas d'appel
      réseau réel).
- [x] **Logging** : ne jamais logger tokens/secrets ; rester sur `debug` pour le pipeline d'upload.
- [x] **Orphelins — `fixOrphan` ne renomme pas** le fichier 1Fichier (contrairement à `update()` quand le filename
      change) : le fichier reste avec son nom original. Amélioration possible plus tard (rename via `rm.cgi` +
      re-upload, non trivial sur 1Fichier).
- [x] **Orphelins — pas de notification Discord** sur `fixOrphan` (l'upload avec envoi Discord reste le flux
      principal ; la récupération d'un orphelin est une réparation silencieuse).
- [x] **Orphelins — `deleteOrphan` vérifie d'abord** que l'`id` est bien un orphelin (`isOrphanFile`) avant
      `rm.cgi`, pour ne jamais pouvoir supprimer le fichier d'un livre vivant.
- [x] **Intrus — détection sur le dossier applicatif** 1Fichier uniquement (même source que `/api/orphans`) ;
      `getStoredIds()` est l'inverse de `getOrphans()`.
- [x] **Intrus — action unique : supprimer l'entrée DB** (le fichier n'existant plus, il n'y a rien à
      « récupérer »). `deleteIntruder` vérifie que l'id n'est pas présent dans le listing avant suppression (409 si
      le fichier existe encore) — on ne peut pas supprimer un livre vivant.

### Reportés

- [ ] **Système de release** (déploiement) : à prévoir.

---

## 7. Critères d'acceptation

> Couverts par les tests (`tests/Controller/`, 1Fichier mocké) sauf mention contraire.

- [x] `POST /api/upload` valide → **201** + Book JSON avec `id` = id 1Fichier, `narrators` en tableau, `ratings` en float.
- [x] `POST /api/upload` sans fichier / mauvais mime → **400** + JSON.
- [ ] Échec 1Fichier (simulé) → **502** et pas de livre persisté — *implémenté (`ApiController` renvoie 502 sur
      exception d'upload), non couvert par un test dédié.*
- [x] `GET /api/download/{id}` connu → **302** avec `Location` valide.
- [x] `GET /api/download/{id}` inconnu → **404**.
- [x] Fournisseur KO (URL vide) → **502**.
- [x] `saga`/`tome` acceptent les valeurs longues (ex. `1.5`, `Tome 2`, `Première partie`).
- [ ] Notification Discord envoyée une fois par upload réussi, lien `.../api/download/{id}` — *implémenté
      (`Notifier::available`), `Notifier` mocké dans les tests sans assertion d'appel.*
- [x] `GET /api/books`, `GET /api/books/{id}`, `GET /api/scrap` inchangés (aucune régression) — suite
      `ScrapControllerTest` verte.
- [x] **Orphelins** : `GET /api/orphans` renvoie les fichiers non référencés ; `POST /api/orphans/{id}` crée le
      `Book` (201) avec les statuts d'erreur 400/404/409/502 ; `DELETE /api/orphans/{id}` supprime (204) avec
      404/502 — couverts par `ApiControllerTest` + `UnFichierApiTest`.
- [x] **Intrus** : `GET /api/intruders` renvoie les `Book` sans fichier sur 1Fichier ; `DELETE
      /api/intruders/{id}` supprime l'entrée (204) avec les statuts d'erreur 404/409/502 ;
      `UnFichierApi::getStoredIds()` est couvert — dans `ApiControllerTest` + `UnFichierApiTest`.
- [x] Suite de tests PHPUnit (1Fichier mocké) verte.
- [ ] Le fichier local ne subsiste pas dans `public/uploads/files` après un upload réussi — *implémenté
      (`unlink(realpath())` dans `ApiController`), non couvert par un test.*

---

## 8. Glossaire

| Terme | Définition |
|-------|-----------|
| **Scraper** | source de métadonnées : `audible` (audible.fr) ou `lizzie` (lizzie.audio) |
| **ScrapId** | identifiant du livre côté source (ASIN Audible / slug Lizzie) |
| **ID 1Fichier** | identifiant de téléchargement renvoyé par `UnFichierApi::upload()` ; stocké comme `Book.id` |
| **Envoi** | pipeline : upload local → upload 1Fichier → création `Book` → notif Discord |
| **Saga** | série/collection de livres ; `tome` = numéro dans la série (stocké en simple colonne) |
| **Orphelin** | fichier 1Fichier sans livre correspondant (`GET /api/orphans`) |
| **Intrus** | livre en base dont le fichier a disparu de 1Fichier (`GET /api/intruders`) |
| **Book JSON** | objet sérialisé API Platform : `id, scraper, scrap_id, title, cover, author, narrators, runtime, ratings, saga, tome` |
