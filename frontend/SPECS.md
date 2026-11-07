# SPECS — Frontend (Vue 3)

> **Statut : implémenté (08/08/2026).**
> La file d'attente envoie réellement et le bouton « Télécharger » fonctionne bout-en-bout (suite PHPUnit verte
> côté backend). Restent les fonctionnalités cibles (§ 5 / § 6) et la persistance de la queue.
> Objectif de ce document : servir de **source de vérité unique** pour le frontend.
> Le `AGENTS.md` du frontend est le mode d'emploi opérationnel ; ce fichier décrit le *quoi*.

---

## 1. Contexte

Frontend d'une bibliothèque personnelle de livres audio (Audible.fr / Lizzie.audio) : parcourir la bibliothèque,
rechercher/éditer les métadonnées d'un livre et l'envoyer vers 1Fichier avec notification Discord.

- **Flux d'ajout** : `DropBox` (fichier) → recherche de métadonnées → **édition des métadonnées** → preview → « Enregistrer » ;
- **File d'attente** (`stores/queue.ts`, `components/queue/FormQueue.vue`) affichée en panneau latéral constant
  dans `App.vue`, donc **indépendante de la navigation** ;
- Regroupement des livres par **saga** (getters du store `book`) ;
- **Stack** : Vue 3, CSS maison (pas de tailwind) — instructions propres aux composants et variables globales
  pour les couleurs.

Ce document capture le comportement actuel, le contrat API et les fonctionnalités cibles.

---

## 2. Exigences produit (non négociables)

- [ ] **UI en français** (libellés, statuts, messages d'erreur).
- [ ] **Thème visuel conservé** : ambre néon (oklch), header Deckard Cain, `.neon`, scrollbars personnalisées.
- [ ] **Queue indépendante de la navigation** : on peut ajouter N livres, naviguer entre les pages, et tout déclencher
      depuis la file — *sans ouvrir un nouvel onglet pour envoyer plusieurs livres* (besoin utilisateur explicite).
- [ ] **Édition des métadonnées avant l'envoi**, y compris après mise en file (draft éditable).
- [ ] L'état de la queue **survit à la navigation** (Pinia global — déjà le cas), perte acceptée au rechargement.
- [ ] Contrat API inchangé (endpoints, formes JSON) — sauf évolution validée côté backend.

---

## 3. Comportement actuel

### 3.1 Parcours utilisateur

- **`/` (HomeView)** : toggle « Sagas / Livres », **mode Sagas par défaut**. Bouton flottant `+` → `/add`,
  **défini au niveau de `App.vue`** : visible sur toutes les routes sauf `/add` (le formulaire y est déjà), donc
  aussi sur saga, édition, orphelins, intrus — **masqué quand la file d'envoi est visible** (il passerait par
  dessus le panneau ; le panneau propose alors un bouton « Ajouter » dans sa barre d'actions, cf. § 3.4).
  Un **input de recherche** à côté du toggle (**rayon 4 px** comme les champs du formulaire) filtre en direct
  (`bookStore` / helpers `bookMatches` / `workMatches` dans `entities/book.ts`) sur : titre, nom de saga, auteur ou
  narrateur — **insensible à la casse et aux accents**, tous les mots du terme doivent matcher (`Aucun résultat` si
  vide). Recherche, mode et tag sont **persistés dans l'URL** (`/?q=…&mode=books&tag=…`, écriture en `router.replace`
  pour ne pas polluer l'historique — cf. § 3.8) : retour, liens et rechargement les restaurent.
  - **Menu de suggestions** (`helpers/suggestions.ts`) : à la saisie, un menu déroulant regroupe jusqu'à
    **5 titres**, **5 sagas**, **5 auteurs** et **5 narrateurs** qui matchent. Navigation clavier
    (↑/↓ + Entrée, surlignage de l'item actif, Échap), fermeture différée au blur et `mousedown.prevent` — pattern
    du sélecteur de saga. Choisir un titre, un auteur ou un narrateur remplit la recherche ; choisir une **saga
    ouvre sa page** — la recherche est alors **réinitialisée** (l'entrée accueil de l'historique est réécrite sans
    `q`) mais le **filtre de tag est conservé**. Un bouton `×` (croix, à droite du champ) efface la recherche.
  - **Filtre par tag** (`helpers/tags.ts`) : un **input dédié à droite** de la recherche, **identique visuellement**
    (même `type="search"`, mêmes styles, même lueur au survol/focus ; largeur plus faible) — la saisie n'y filtre
    **pas** automatiquement : un menu déroulant propose jusqu'à **5 tags** qui
    matchent (triés par popularité, `collectTags`), mais le filtre ne s'applique qu'à la **validation** —
    `Entrée` avec le texte saisi (appliqué tel quel — le filtrage est une égalité sans casse ni accents) ou clic
    sur une suggestion (appliquée immédiatement). À la validation, l'input **s'efface au profit d'une chip**
    (pill ambre, même style que les chips de tags du formulaire) ; la croix de la chip retire le filtre et
    **fait revenir l'input** (focus remis). Le filtre se **compose avec la recherche** (ET) ; `?tag=Nom du tag`
    est persisté dans l'URL comme `?q=`/`?mode=` (l'input se resynchronise sur les navigations). Un livre est
    affiché s'il porte le tag (`hasTag`) ; une saga est affichée dès qu'**au moins un** de ses livres le porte
    (`workHasTag`) — et la vignette montre alors **tous** ses livres, indépendamment du tag (l'expansion reste le
    comportement des vignettes). Les livres isolés (ou saga à 1 livre) suivent la même règle via leur livre.
  - Mode **Sagas** : une vignette `SagaTile` par saga de **2+ livres** — titre en `<h2>` comme les cartes livre,
    layout `book-core` : **carré 2×2 de couvertures** dans la colonne gauche (à l'emplacement de la couverture,
    4e case = couverture du 4e livre estompée + « +N » si plus de 4 livres),
    **infos compilées** à droite : Auteur (du 1er livre par tome), Narrateur(s) (union **sans doublon** des
    narrateurs de tous les livres), Livres (« N livres », à la place de la section
    Saga des cartes), Durée **cumulée** de tous les livres (somme HH:MM, « + de X jours » au-delà de 2 jours) ;
    pied de vignette : « Voir les livres » (style lien, comme « Télécharger ») à gauche, **sans compteur** en bas
    à droite ; la vignette elle-même **n'est pas cliquable** — seul « Voir les livres » pointe vers `/saga/:id` —
    et cartes `BookLarge` pour les livres « normaux » (sans saga, ou saga à un seul livre).
    **Tri alphabétique** sur le nom de saga, sinon sur le titre du livre (`bookStore.works`).
  - Mode **Livres** : grille de toutes les cartes `BookLarge` (tri alphabétique titre) ; **compteur de tags façon
    note** — icône étiquette diagonale (`IconTag`, corps arrondi + œillet, axe « \\ ») + **nombre seul, sans cadre**
    (masqué sans tags), à côté de la note dans l'en-tête — au survol, un panneau liste les tags
    verticalement (fond `--color-background-mute`, bordure ambre) ; le panneau **épouse le tag le plus long**
    (`width: max-content`, **aucun plafond en bibliothèque** — un tag tient toujours en entier) ; **preview du
    formulaire : plafond 16rem** — un tag très long passe à la ligne (`overflow-wrap`), jamais de débordement
    (le `white-space: nowrap` du compteur ne descend pas dans le panneau). **Clic sur un tag → retour à l'index filtré**
    (`/?tag=…`, glow au survol ; pont invisible compteur → panneau pour ne pas perdre le hover). **Preview du
    formulaire : panneau inerte** (`pointer-events: none`, non cliquable, affiché au survol du compteur seul).
    Présente sur toute fiche `BookLarge` (bibliothèque, isolés d'une saga, preview du formulaire) ; actions par
    carte : « Télécharger » (`LinkDownload`) et lien source Audible/Lizzie (`LinkScrapper`).
  - Cliquer sur une vignette saga → page dédiée **`/saga/:id`** (id local dérivé du nom de saga) : `SagaView`
    liste les livres de la saga **triés par tome** (`bookStore.sagaById` + `bookStore.fromSaga`). Le
    « ← Retour » du header revient dans l'historique (`historyStore.backOrHome()` — cf. § 3.8).
- **`/add` (FormView)** :
  1. `DropBox` : drag & drop ou clic → le **nom du fichier pré-remplit la recherche**, nettoyé par
     `filenameToSearchTerm()` (`helpers/search.ts`) : extension, séparateurs (`._-[](){}…`), marqueurs de tome
     (« tome 1 », « t2 », nombres ≤ 2 chiffres), tags qualité (« mp3 », « 144kbps », « v2 »…) et déterminants
     (le, la, les, un, du, des…) retirés ; l'apostrophe est conservée (« l'école » reste un token). Retombe sur le
     nom brut si tout est filtré.
     Le fichier doit porter l'extension `.zip` (`accept=".zip"` + contrôle client) — sinon message
     « Seuls les fichiers .zip sont acceptés. ».
  2. La recherche (`search()`) est lancée avec **debounce 200 ms** + `AbortController` (signal transmis au fetch) ;
     les réponses périmées (recherche plus récente lancée) sont jetées ; les résultats **non vides sont mis en
     cache** côté client (`searchCache` FIFO 50 entrées dans `api/book.ts`) — retaper la même recherche ne
     re-scrape pas Audible. Une erreur réseau affiche « La recherche a échoué… » + bouton « Réessaie »
     (avant : le spinner restait bloqué).
  3. Suggestions affichées en tuiles `BookCard` (grille `repeat(auto-fill, 9rem)` ≈ 1/3 d'une carte bibliothèque).
     Le **bloc `.suggestions`** (recherche + résultats) est la **zone défilante** (max-height 60vh, scrollbar ambre
     fine) — la liste `.results` n'a pas de hauteur propre et les cartes clipsent leur contenu
     (`.suggestion { overflow: hidden }`). Un clic **remplit tous les champs** (scraper, scrapId, cover, title,
     author, narrators, tags, runtime, ratings, saga, tome) — les **tags** sont ensuite **scrapés à la volée** sur
     la fiche Audible du livre sélectionné (`scrapTags()` → `GET /api/scrap/tags`, seulement pour `scraper=audible`,
     spinner dans le champ pendant le scrap ; ils n'apparaissent pas sur les pages recherche/série ; une réponse
     périmée — sélection plus récente — est jetée, un échec laisse le champ tel quel).
  4. L'utilisateur **édite librement** les champs ; la preview (`BookLarge`, sans download) se met à jour en direct.
  5. « Enregistrer » → ajoute `{ state: 'draft', book }` à la **queue** et **reset** le formulaire
     (**pas de navigation** : on peut enchaîner les livres).
  6. Si aucun résultat : message « Aucun résultat trouvé pour _X_ » + « mais vous pouvez saisir manuellement les
     métadonnées ! » (le bouton « Affinez-le » a été retiré — la saisie manuelle est la seule suite).
- **Queue (panneau latéral droit, visible sur toutes les routes)** : drafts listés en `BookCard` compact horizontal
  (couverture, titre, auteur, statut) + boutons **Envoyer** (tous → `pending`) et **Annuler** (retire les drafts). Cliquer sur un élement doit ouvrir le formulaire associé pour modification. De là on peut aussi le supprimer.
  En dessous de 1024px, la queue passe **sous le formulaire** (33vh) et les drafts reprennent le format tuile des
  suggestions (`BookCard` sans `.horizontal`), sur 2 colonnes — même composant, disposition commutée en CSS.

### 3.2 Recherche de métadonnées

- `normalizeSearch()` (`api/book.ts`) : minuscules, suppression des accents (NFD), caractères non alphanumériques → espaces,
  retrait des caractères isolés. Envoyé tel quel à `GET api/scrap?pattern=…`.
- Réponse : fusion des scrapers **Audible** (page audible.fr) et **Lizzie** (catalogue en cache), **classée par
  pertinence côté backend** (`SearchRanker::merge()` : scoring, dédup titre+auteur, plafond 30 ; matching Lizzie
  tolérant aux accents/apostrophes et à l'ordre des mots). Un livre Audible **sans narrateur est filtré côté
  backend**. Un scraper en échec ne prive jamais des résultats de l'autre.
- Résultats **non vides mis en cache** client (`searchCache`, FIFO 50) ; `clearSearchCache()` reset (tests).
  Une recherche peut être annulée (`signal`) ; les réponses périmées sont jetées (`BookForm`).
- Sélection d'une suggestion → mapping vers les champs du formulaire.

### 3.3 Édition des métadonnées — fonctionnalité clé

Champs du formulaire (`FormView`) :

| Champ | Champ interne | Format |
|-------|---------------|--------|
| Couverture | `cover` | URL |
| Titre | `title` | texte |
| Auteur | `author` | texte |
| Narrateurs | `narrators` | texte, **split sur `/,\s*/`** → tableau |
| Durée | `runtime` | `H:i` |
| Note | `ratings` | nombre (0 si vide) |
| Saga | `saga.name` | `FormSagaSelect` (voir ci-dessous) |
| Tome | `saga.tome` | texte, affiché si une saga est saisie |
| Tags | `tagChips` | **boîte à chips en fin de formulaire** (après Couverture) — voir ci-dessous |

Ordre des champs : Titre, Auteur, Narrateurs, Durée, Note, Saga, (Tome si saga), Couverture, **Tags**.

- **Champ Tags à chips** (`BookForm.vue`) : un cadre façon input (bordure ambre, rayon 4, lueur au
  `:focus-within`) contenant les **chips** (petites pills ambre avec une croix à droite pour les retirer) puis
  un **input sans bordure** pour chercher ou saisir un tag. Les tags scrapés d'une fiche Audible pré-remplissent
  les chips (cf. § 3.1, `/add` étape 3) ; chaque chip est retirée individuellement (croix).
  **Marqueur « tag nouveau »** : une chip dont le tag **n'existe pas dans la bibliothèque** (comparaison `loose()`
  contre `collectTags(bookStore.books)`) porte une **bordure pointillée** et une info-bulle
  (« Nouveau tag ») — il sera donc créé à l'enregistrement ; les chips connues restent à bordure pleine (reconnaissance
  sans casse ni accents).
- **Autocomplétion « à la manière des sagas »** : les suggestions viennent d'une **collecte des livres de la
  bibliothèque** (`collectTags()` dans `helpers/tags.ts` — pas de gestion de tags en base), **hors des chips
  déjà saisies** et filtrées sur le texte tapé (**casse et accents ignorés**, `loose()`). `Entrée` avec une
  suggestion active (navigation `↑`/`↓`, surlignage comme le sélecteur de saga) l'ajoute ; `Entrée` sans
  suggestion **crée** le tag tapé (jamais de soumission du formulaire — le handler fait `preventDefault`).
  Un doublon (casse/accents compris) n'est jamais ajouté : la saisie est simplement vidée. `Échap` ferme le
  dropdown ; fermeture différée au blur (150 ms, pattern `FormSagaSelect` — `@mousedown.prevent` sur les items).
- `FormSagaSelect` : combobox — input avec dropdown des sagas connues (getter `bookStore.sagas`, déduites de la
  bibliothèque), filtrées sur la saisie **sans casse ni accents** ; saisie libre pour créer. Le `v-model` est le
  **nom** de la saga.
- Le « livre virtuel » (`computed`) reconstruit un `Book` complet ; il est utilisé pour la preview **et** l'enqueue.

### 3.4 Queue (implémentation actuelle)

- **Store** `stores/queue.ts` : `uploads: QueueItem[]`,
  `QueueItem = { state, book, file, progress, log, controller? }` (`controller` = `AbortController` de l'upload en cours).
- **États** : `draft` → `pending` → `running` → `transferring` → `paused` → `ended` (libellés français via
  `statusLabel`). L'item passe en `transferring` quand l'upload client atteint 100 % (le XHR est fini, le serveur
  opère le transfert 1Fichier + l'indexation) : plus rien ne peut l'interrompre.
- **Getters** : `drafts` (state === 'draft'), `pending` (state === 'pending'), `running` (au moins un en
  `running` **ou** `transferring`), `launchable` (au moins un en `draft` ou `pending`).
- `FormQueue.vue` : panneau latéral dans `App.vue` (donc **global**), liste des items, bouton Envoyer (s'il y a des
  items `launchable`, désactivé si un envoi est en cours) et retrait des items `ended`/`error`.
- **« Envoyer » (submit)** : passe tous les `draft` → `pending`, puis traite **la photo des `pending`** à l'instant de
  départ. Chaque item est lancé dès que l'**upload client** de l'item précédent est terminé (item passé en
  `transferring`) : **le transfert serveur (1Fichier, réécriture Book, notif Discord) se poursuit en parallèle** de
  l'upload de l'item suivant. En fin de traitement, les items relancés en `pending` pendant la file **restent en
  attente** (relancer « Envoyer » pour les traiter). Un `paused` n'est jamais traité tant qu'il n'est pas repris.
- **Barre d'actions** : bouton **« Ajouter »** (lien vers `/add`, même rôle que le bouton flottant « + » de la
  bibliothèque — qui est masqué tant que le panneau est visible pour ne pas le recouvrir ; masqué aussi sur `/add`
  même), puis « Pause » (file) et « Envoyer ». Quand **toute la file est terminée** (getter `finished` : tous les
  items `ended`/`error`, aucun actif ni brouillon — ni « Pause » ni « Envoyer » ne sont alors visibles), un bouton
  **« Vider la file »** apparaît : confirmation (`window.confirm`) puis `queueStore.clear()` (refusé côté store tant
  que la file n'est pas terminée).
- **« Envoyer » envoie réellement** : chaque item passe en `running` puis `transferring` puis `ended`/`error` via
  `uploadBook()` (XHR, `POST /api/upload`), la bibliothèque est rafraîchie après chaque succès.
- **Pause / Reprendre (bouton par item)** : bouton dispo tant que l'item est `draft`, `pending` ou `running` — il
  interrompt un upload en cours (`AbortController.abort()`) et passe l'item en `paused`, sinon il passe l'item
  non lancé en `paused`. Dès que l'item est `transferring` (ou `ended`/`error`), la pause n'est plus possible. Le
  *resume* repasse l'item en `pending`.
- **Pause / Reprendre (bouton « Pause » de la file entière, barre d'actions)** : `pauseAll()` aborte l'upload en
  cours et met en `paused` tous les items `draft`/`pending`/`running` (jamais un `transferring`) ; visible tant
  qu'au moins un item est pausable. Reprise item par item.
- **Réessayer (bouton par item)** : un item `error` propose « Réessayer » qui relance la tentative (`session de file
  séparée`) — l'item repasse en `running` sans toucher au reste de la file (désactivé si un upload est en cours).
- **Progression** : `item.progress` (0–100) alimenté par `xhr.upload.onprogress` (`onUploadProgress`), affiché en
  `<progress>` par item tant qu'il est `running` (et au démarrage de `transferring`).
- **Log d'avancement** : `item.log[]` par item, étapes affichées : `Envoi du fichier…` (%), `Transfert vers le
  serveur de stockage…` (déclenché à 100 % d'upload client, couvre transfert 1Fichier + indexation du
  serveur), puis après réponse 201 `Enregistrement en base.`, `Notification Discord envoyée.`, `Terminé.` ; en cas
  d'échec (400/500/502), `Erreur : <message backend>`.
- Clé des items Vue : index local (les drafts peuvent partager le même `book.scrapId`).

### 3.5 Limites connues

- **Pas de persistance** de la queue (rechargement = perte de la file, cf. § 6).
- `saga.id` côté front est un **uid local dérivé du nom de saga** (`bookStore.sagas`) — pas d'identité côté
  backend (le backend stocke `saga` en simple colonne).

---

### 3.6 Fichiers orphelins 1Fichier

Un **fichier orphelin** = un fichier présent dans le dossier 1Fichier de l'app dont le hash d'accès ne correspond
à aucun `Book` en bibliothèque (résidu d'upload, fichier déposé à la main…). `GET /api/orphans` le liste (voir § 4).

- **Accès** : page `/orphans`, accessible depuis la bibliothèque (lien « Fichiers orphelins »), **hors navigation**
  principale (hors panneau de queue et bouton `+`).
- **Liste** : tuiles des orphelins (nom + taille au Mo) ; clic → formulaire « Nouveau livre ».
- **Formulaire de récupération** : réutilise `BookForm.vue` (extrait de `FormView` pour le partager entre
  `/add`, `/edit/:id` et `/orphans`). Comme en `/add` : recherche de métadonnées **pré-remplie avec le nom du
  fichier orphelin** (extension retirée, `searchTerm` dérivé du nom sélectionné — réutilise le mécanisme
  « fichier déposé » de `/add`), édition libre, preview en direct, détection de **doublon** (même `scraper` +
  `scrapId` qu'un livre existant, hors celui en édition — bandeau d'alerte en haut du formulaire encourageant la
  suppression du doublon).
- **« Enregistrer »** → `fixOrphan(id, book)` (`POST /api/orphans/{id}`) → rafraîchit la bibliothèque **et** la
  liste des orphelins, retour à la liste. Échec → message dans `BookForm` (prop `error`).
- **« Supprimer »** (bandeau du formulaire, icône `IconTrash`) → `window.confirm` → `deleteOrphan(id)`
  (`DELETE /api/orphans/{id}`) → sélection suivante (ou prédécente, sinon retour à la liste). Confirmation requise
  car la suppression est définitive sur 1Fichier.
- **Note affichée** : « Enregistrer ne renomme pas le fichier sur 1Fichier. » (le nom d'un orphelin récupéré ne
  change pas, contrairement à l'upload classique).

---

### 3.7 Intrus

Un **intrus** = un `Book` de la bibliothèque dont le fichier a **disparu** du dossier 1Fichier de l'app
(l'inverse d'un orphelin : l'entrée en base existe, aucun fichier ne lui correspond). Cause typique : fichier
supprimé/purgé sur 1Fichier, ou upload « réussi » dont le fichier n'a jamais été indexé. `GET /api/intruders` le
liste sous forme de `Book JSON` (voir § 4).

- **Accès** : page `/intruders`, au même niveau que `/orphans` (hors navigation principale).
- **Liste** : une carte par intrus (titre, auteur, saga/tome, `id` 1Fichier) + bouton « Supprimer ».
- **« Supprimer »** → `window.confirm` → `deleteIntruder(id)` (`DELETE /api/intruders/{id}`) → la liste est
  rafraîchie. La suppression ne touche **pas** 1Fichier (le fichier n'existe pas) : elle retire l'entrée en base.
- **Pas de formulaire de récupération** : aucun fichier à « récupérer » (décision, cf. `backend/SPECS.md` § 6).

---

### 3.8 Historique de navigation

L'app dispose d'un **historique de navigation** (store `stores/history.ts`) pour ne plus perdre l'état en
revenant en arrière (ex. recherche → saga → retour). Pas de bouton « Suivant » : un seul **« ← Retour »**.

- **« ← Retour » dans le header** (`App.vue`, donc visible sur toutes les routes **sauf l'accueil**, qui est la
  racine) : `historyStore.backOrHome()` — retour à la page précédente (état restauré), accueil en accès direct
  (pas d'historique). Remplace l'ancien lien « Retour » de la page saga (servait aussi les pages `/orphans` et
  `/intruders`, qui n'en avaient pas).
- **Position dans la pile** : vue-router tient la position dans `history.state.position` ; le store mémorise la
  **borne minimale visitée** (`min`) et en déduit `canBack`. `track()` est câblé sur `router.afterEach`
  (dans `main.ts`). La borne est **persistée en sessionStorage** (par onglet) pour survivre au rechargement.
- **État des pages dans l'URL** : HomeView persiste la recherche (`q`), le mode (`mode=books`) et le filtre de tag
  (`tag`) dans la query —
  écriture en **`router.replace`** (la saisie ne crée pas d'entrées d'historique), relecture sur `route.query`
  (retour, liens). La recherche survit donc à navigation → saga → retour.
- **Formulaire** (`/add`, `/edit/:id`) :
  - « Annuler » (édition) et la sauvegarde réussie reviennent **d'où on venait** (`backOrHome()`), au lieu de
    toujours pousser l'accueil ;
  - le fichier déposé ne peut pas survivre à la navigation : quitter `/add` avec un fichier en attente déclenche
    une **confirmation** (`onBeforeRouteLeave`) ;
  - en création, « Enregistrer » met en file et **reste sur le formulaire** (inchangé — pas de navigation).
- **Limites** : la position de scroll n'est pas restaurée (conteneurs de scroll custom) ; l'état du formulaire
  n'est pas dans l'URL (le `File` n'est pas sérialisable).

---

## 4. Contrat API backend

### `GET /api/books?pagination=false`
```json
{ "member": [ /* Book JSON */ ] }
```
Utilisé par `fetchBooks()`. Champs : `id, scraper, scrap_id, title, cover, author, narrators, tags, runtime, ratings, saga, tome`.
`narrators` et `tags` sont des **tableaux** (décision actée).

**Cache HTTP (ETag / 304)** : le serveur calcule un hash de version de la bibliothèque à chaque requête et renvoie
`ETag` + `Cache-Control: private, max-age=0, must-revalidate` sur les **200**, et **304** (corps vide) quand le
client présente la liste via `If-None-Match`. Le frontend revalide à chaque `fetchBooks()` (`cache: 'no-cache'`,
`If-None-Match` mémorisé) et **réutilise la liste en cache** sur 304 (même référence : `$patch` no-op côté stores).
`clearBooksCache()` (`src/api/book.ts`) réinitialise ce cache (tests / rafraîchissement forcé).

### `GET /api/scrap?pattern=…`
```json
[ /* Book JSON, même forme */ ]
```
Recherche fusionnée Audible + Lizzie. (Livres Audible sans narrateur filtrés.)

### `GET /api/scrap/tags?scraper=audible&scrap_id=…`
```json
{ "tags": ["Fantasy", "Épique"] }
```
Tags (genres) de la **fiche Audible** — absents des pages recherche/série (recherche et page série). Consommé par
`scrapTags()` à la sélection d'une suggestion du formulaire ; résultats non vides mis en cache client
(`tagsCache` FIFO 50, `clearTagsCache()` pour reset). **400** si `scraper` ≠ `audible` ou `scrap_id` absent ;
échec du scrap → `{"tags": []}` (liste vide, non mise en cache). Lizzie n'expose pas de tags.

### `POST /api/upload`

Contrat — **multipart/form-data** :
- Champs texte (noms nus, préfixe de formulaire `''`) : `author, title, cover, saga, tome, narrators, tags, runtime,
  ratings, scraper, scrap_id` (chaînes, vides autorisées).
- `file` : **fichier ZIP obligatoire** (extension `.zip` côté front + back), mimes par contenu `application/zip`,
  `application/octet-stream`, `application/x-zip-compressed`, `multipart/x-zip` ; côté frontend `accept=".zip"` + contrôle
  d'extension dans `DropBox` (message « Seuls les fichiers .zip sont acceptés. »).
- Flux : validation → upload local (`LocalFileUploader`) → upload 1Fichier (`UnFichierApi::upload`) →
  `book.id` = id de téléchargement 1Fichier → suppression du fichier local → persist `Book` →
  notification Discord (`Notifier::available`) → réponse Book sérialisé.

**Détails** :
- `narrators` et `tags` : le champ multipart reste une **chaîne CSV**, le backend la convertit en **tableau** —
  l'API les expose en tableau dans toutes les réponses.
- `saga` : `VARCHAR(255)`, sans normalisation automatique ; `tome` : `VARCHAR(40)`.
- Statuts : **201** en succès (Book JSON) ; **400** si formulaire invalide (message d'erreur) ; **500** si échec
  persist, **502** si échec d'indexation/upload 1Fichier.
- Sérialisation : format API Platform (mêmes champs que `GET /api/books`).

### `GET /api/download/{id}`

Contrat : `find(id)` → `UnFichierApi::download(id)` → **302 `Location: <URL temporaire 1Fichier>`**.
Statuts : **404** si livre inconnu ; **502** si le fournisseur ne renvoie pas d'URL.

### Orphelins 1Fichier

- `GET /api/orphans` → `200 [ { id, name, size, date }, … ]` (liste vide = `[]`). **502** si le listing
  1Fichier échoue. Consommé par `fetchOrphans()` (`api/orphans.ts`, mapping vers `Orphan`) — **lève une erreur**
  (message du serveur) sur statut ≠ 200, pour ne jamais afficher un faux état « aucun orphelin » si 1Fichier échoue.
- `POST /api/orphans/{id}` — corps JSON `bookPayload(book)` (même contrat que la mise à jour d'un livre) :
  **201** + Book JSON ; **400** `{"error":"Le titre est requis."}` (ou JSON invalide) ; **404** si l'id n'est pas
  un orphelin ; **409** si un livre a déjà cet `id` ; **502** si le listing échoue. Consommé par `fixOrphan()`.
- `DELETE /api/orphans/{id}` → **204** (supprimé) ; **404** si pas un orphelin ; **502** si la suppression 1Fichier
  échoue. Consommé par `deleteOrphan()`.

Sécurité backend : `DELETE` (et `POST`) vérifie que l'`id` est bien un orphelin avant d'agir — impossible de
supprimer/écraser le fichier d'un livre vivant.

### Intrus

- `GET /api/intruders` → `200 [ Book JSON, … ]` (liste vide = `[]`). **502** si le listing 1Fichier échoue.
  Consommé par `fetchIntruders()` (`api/intruders.ts`, mapping via `bookCollectionFromApi` de `api/book.ts`) —
  **lève une erreur** (message du serveur) sur statut ≠ 200, pour ne jamais afficher un faux état « aucun intrus »
  si 1Fichier échoue.
- `DELETE /api/intruders/{id}` → **204** (entrée supprimée) ; **404** si livre inconnu ; **409** si le fichier est
  encore présent sur 1Fichier ; **502** si le listing échoue. Consommé par `deleteIntruder()`.

Sécurité backend : la suppression vérifie que l'`id` n'est **pas** présent dans le listing 1Fichier avant de
supprimer l'entrée — impossible de supprimer un livre vivant.

---

## 5. Fonctionnalité cible : édition des métadonnées avant envoi

Objectif : l'utilisateur contrôle **tout** ce qui est envoyé, avant l'envoi.

Comportements requis :
- [ ] Le formulaire existant (recherche → remplissage → édition → preview) est **conservé** et refactoré proprement.
- [ ] Un **draft de la queue est éditable** : rouvrir le formulaire pré-rempli, modifier, re-sauvegarder.
- [x] Règles de validation **actées le 08/08/2026** :
      - titre requis
      - fichier obligatoire : un draft sans fichier (saisi « à la main ») ne peut pas être envoyé
      - narrateurs : split par virgule (envoyer un tableau)
- [ ] Indiquer visuellement les champs obligatoires / erreurs (sans casser le thème).
- [ ] Un item ne peut être envoyé que s'il est complet (selon règles ci-dessus) ; sinon bouton Envoyer désactivé
      avec message explicite.

---

## 6. Fonctionnalité cible : queue indépendante de la navigation

Besoin utilisateur : **« envoyer plusieurs livres sans être obligé d'ouvrir un nouvel onglet »**.

Comportements requis :
- [ ] La file est **globale** (déjà le cas : store Pinia + panneau dans `App.vue`) et visible sur toutes les routes.
- [ ] Ajout de N items (drafts) depuis `/add` **sans naviguer** (déjà le cas).
- [ ] **Envoi depuis la file** : un bouton par item et/ou « Tout envoyer ».
- [ ] **Traitement séquentiel** : les envois se déroulent un par un, en arrière-plan — l'utilisateur continue de
      naviguer et d'ajouter pendant l'envoi.
- [ ] États visibles par item : `draft → pending → running → transferring → paused → ended` (+ `error`).
      Progression par item (fichier % , étapes) et progression globale.
- [x] **Progression** : `uploadBook()` — `POST /api/upload` multipart en
      **XHR** avec `xhr.upload.onprogress` (%, barre par item) + log d'avancement par item. Le traitement de la file
      ne bloque pas la navigation.
- [x] **Pause / Reprendre par item** : bouton par item — dispo tant que l'item est `draft`/`pending`/`running` ;
      l'upload en cours est interrompu via `AbortSignal` (`uploadBook` option `signal`), l'item passe en `paused`,
      et le *resume* le repasse en `pending`. Impossible dès `transferring` (le XHR client est fini, le serveur
      transfère vers 1Fichier) et sur `ended`/`error`. À la fin d'un lancement, les items restés `pending` restent
      en attente jusqu'au prochain « Envoyer ».
- [x] **Pause de la file entière** : `pauseAll()` met en `paused` tous les items `draft`/`pending`/`running` et
      aborte l'upload en cours (jamais un `transferring`).
- [x] **Réessayer après échec** : l'action `retry(item)` relance l'upload d'un item `error` individuellement (sans
      relancer le reste de la file) ; désactivée si un autre upload est en cours.
- [ ] **Gestion d'échec** : item en erreur → retry (manuel) ; continuer la file.
- [ ] **Persistance** : la file ne survit pas au rechargement.
- [x] **Ordre** : FIFO par défaut ; **réordonner par flèches haut/bas** dans un premier temps (drag & drop plus tard).
- [x] **Confirmation de suppression pour tout** (draft et item déjà envoyé). Impossible de supprimer un livre envoyé sur le serveur.
- [ ] **Identité stable** des items de la file : ne plus dépendre de `book.scrapId` (uid local par item, genre UUID).
- [ ] **Parallélisme** : les uploads client restent séquentiels, mais le **transfert serveur** d'un item se poursuit
      en parallèle de l'upload de l'item suivant (la file passe au suivant dès 100 % côté client).
- [ ] **Identification** : utiliser un uuid pour ls drafts, sera remplacé par son id (1Fichier) une fois qu'il aura été établi.

---

## 7. Décisions actées

- [x] **Arborescence** : composants atomiques. Conserver la structure `api/ entities/ stores/ views/ components/`
      **+ ajouter un dossier `helpers`** (logique utilisée par les composants, indépendante de ceux-ci).
- [x] **Tests** : framework **Vitest + @vue/test-utils** ; prioriser queue, form, mapping API.
- [x] **i18n** : pas de couche i18n.

---

## 8. Glossaire

| Terme | Définition |
|-------|-----------|
| **Scraper** | source de métadonnées : `audible` (audible.fr) ou `lizzie` (lizzie.audio) |
| **ScrapId** | identifiant du livre côté source (ASIN Audible / slug Lizzie) |
| **Draft** | item en file, non envoyé, éditable |
| **Queue / file** | liste d'items à envoyer, indépendante de la navigation |
| **Envoi** | pipeline : upload local → upload 1Fichier → création `Book` → notif Discord |
| **Saga** | série/collection de livres ; `tome` = numéro dans la série |
