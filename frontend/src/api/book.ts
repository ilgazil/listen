import { Book } from '@/entities/book.ts'
import { Saga } from '@/entities/saga.ts'
import { mapArray, mapIn, mapObject } from '@/api/core.ts'
import { API_BASE_URL } from '@/api/config.ts'

function sagaUid(name: string): string {
  let hash = 0

  name = name.trim().toLowerCase()

  for (let i = 0; i < name.length; i++) {
    hash = (hash * 31 + name.charCodeAt(i)) | 0
  }

  return String(hash >>> 0)
}

function sagaFromApi(data: unknown): Saga | undefined {
  const source = mapObject(data)

  if (!source || !source.saga) {
    return
  }

  const saga = new Saga()

  saga.id = sagaUid(String(source.saga))
  saga.name = String(source.saga || '')
  saga.tome = String(source.tome || '')

  return saga
}

function bookFromApi(data: unknown): Book | undefined {
  const source = mapObject(data)
  if (!source) {
    return
  }

  const book = new Book()

  book.id = String(source.id || '')
  book.scraper = mapIn(source.scraper, ['audible', 'lizzie']) || 'audible'
  book.scrapId = String(source.scrap_id || '')
  book.title = String(source.title || '')
  book.cover = String(source.cover || '')
  book.author = String(source.author || '')
  book.narrators = mapArray(source.narrators)?.map(String) || []
  book.runtime = String(source.runtime || '')
  book.ratings = Number(source.ratings) || 0
  book.saga = sagaFromApi(source)

  return book
}

export function bookCollectionFromApi(data: unknown): Array<Book> {
  return (
    mapArray(data)
      ?.map(bookFromApi)
      .filter<Book>((_) => !!_) || []
  )
}

export function normalizeSearch(pattern: string): string {
  pattern = pattern.toLowerCase()

  // Supprime les caractères accentués
  pattern = pattern.normalize('NFD').replace(/[\u0300-\u036f]/g, '')

  // Retire tout caractère autre qu'un mot ou un chiffre
  pattern = pattern.replace(/[^\w\s]/g, ' ')

  // Retire tous les caractères isolés
  pattern = pattern
    .replace(/\s(.\s)+/g, ' ')
    .replace(/^.\s/g, ' ')
    .replace(/\s.$/g, ' ')
    .trim()

  return pattern
}

// Cache de la bibliothèque : le serveur fournit un ETag (hash de la version de
// la collection, calculé à chaque requête) et répond 304 si la représentation
// n'a pas changé. On revalide à chaque appel et on réutilise la liste en cache.
let booksCache: Array<Book> | null = null
let booksEtag: string | null = null

export async function fetchBooks(): Promise<Array<Book>> {
  const headers: Record<string, string> = {}

  if (booksEtag) {
    headers['If-None-Match'] = booksEtag
  }

  const result = await fetch(`${API_BASE_URL}/api/books?pagination=false`, {
    headers,
    // Revalidation explicite : pas de cache HTTP local qui masquerait le 304.
    cache: 'no-cache',
  })

  if (result.status === 304) {
    // La bibliothèque n'a pas changé : on renvoie la même référence qu'en
    // cache → `$patch({ books })` est un no-op côté stores (pas de re-render).
    return booksCache ?? []
  }

  const data = await result.json()
  const books = bookCollectionFromApi(data.member)

  const etag = result.headers.get('ETag')

  if (etag) {
    booksEtag = etag
  }

  booksCache = books

  return books
}

// Réinitialise le cache de la bibliothèque (tests, rafraîchissement forcé).
export function clearBooksCache(): void {
  booksCache = null
  booksEtag = null
}

// Cache mémoire des recherches : /api/scrap scrape Audible côté serveur (lent),
// retaper la même recherche ne doit pas re-scrape. Seuls les résultats non vides
// sont mis en cache (un échec ou un « aucun résultat » doit pouvoir réessayer).
const searchCache = new Map<string, Array<Book>>()
const SEARCH_CACHE_MAX = 50

// Réinitialise le cache des recherches (tests, rafraîchissement forcé).
export function clearSearchCache(): void {
  searchCache.clear()
}

export async function search(pattern: string, signal?: AbortSignal): Promise<Array<Book>> {
  const key = normalizeSearch(pattern)

  if (!key) {
    return []
  }

  const cached = searchCache.get(key)

  if (cached) {
    return cached
  }

  const response = await fetch(
    `${API_BASE_URL}/api/scrap?pattern=${encodeURIComponent(key)}`,
    ...(signal ? [{ signal }] : []),
  )
  const books = bookCollectionFromApi(await response.json())

  if (books.length) {
    if (searchCache.size >= SEARCH_CACHE_MAX) {
      const oldest = searchCache.keys().next().value

      if (oldest !== undefined) {
        searchCache.delete(oldest)
      }
    }

    searchCache.set(key, books)
  }

  return books
}

export interface UploadOptions {
  onProgress?: (percent: number) => void
  signal?: AbortSignal
}

export function uploadBook(book: Book, file: File, options: UploadOptions = {}): Promise<Book> {
  const form = new FormData()

  form.append('author', book.author)
  form.append('title', book.title)
  form.append('cover', book.cover)
  form.append('saga', book.saga?.name || '')
  form.append('tome', book.saga?.tome || '')
  form.append('narrators', book.narrators.join(', '))
  form.append('runtime', book.runtime)
  form.append('ratings', String(book.ratings || ''))
  form.append('scraper', book.scraper)
  form.append('scrap_id', book.scrapId)
  form.append('file', file)

  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    xhr.open('POST', `${API_BASE_URL}/api/upload`)
    // Texte plutôt que 'json' : en cas d'échec, le corps brut porte le détail
    // (page d'erreur de l'hébergeur, message du backend…) que le mode json masque.
    xhr.responseType = 'text'

    const handleAbort = (): void => xhr.abort()

    if (options.signal?.aborted) {
      xhr.abort()
    } else {
      options.signal?.addEventListener('abort', handleAbort, { once: true })
    }

    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable && event.total > 0) {
        options.onProgress?.(Math.round((event.loaded / event.total) * 100))
      }
    }

    xhr.onload = () => {
      options.signal?.removeEventListener('abort', handleAbort)

      let data: unknown = null

      try {
        data = JSON.parse(xhr.responseText)
      } catch {
        // Corps non JSON (page d'erreur du serveur…) : le message d'échec
        // inclura un extrait brut.
      }

      if (xhr.status !== 201) {
        reject(new Error(describeUploadFailure(xhr.status, data, xhr.responseText)))
        return
      }

      resolve(bookFromApi(data) || book)
    }

    xhr.onerror = () => {
      options.signal?.removeEventListener('abort', handleAbort)
      reject(new Error("Erreur réseau pendant l'envoi."))
    }

    xhr.onabort = () => {
      reject(new Error('Envoi interrompu.'))
    }

    xhr.send(form)
  })
}

// Message d'échec d'upload : l'erreur JSON du backend si présente, sinon un
// extrait lisible du corps brut (page 503 de l'hébergeur, erreur PHP…).
function describeUploadFailure(status: number, data: unknown, body: string): string {
  const error = mapObject(data)?.error

  if (error) {
    return String(error)
  }

  const snippet = htmlToSnippet(body)

  return `Échec de l'envoi (${status})` + (snippet ? ` — ${truncate(snippet)}` : '')
}

// Corps d'une page d'erreur → texte lisible : scripts/styles/commentaires retirés
// (leurs contenus polluent : code, « */--> »), entités HTML décodées
// (&egrave; → è), espaces réduites.
// Corps d'une page d'erreur → texte lisible : parser le HTML d'abord (le DOM
// décode les entités : &egrave; → è), retirer scripts et styles (leurs contenus
// polluent : code, « */--> »), puis lire le texte du <body> seul — le <title>
// duplique souvent le premier message de la page.
function htmlToSnippet(body: string): string {
  const document = new DOMParser().parseFromString(body, 'text/html')

  document.querySelectorAll('script, style').forEach((element) => element.remove())

  const text = (document.body ?? document.documentElement)?.textContent ?? ''

  return text.replace(/\s+/g, ' ').trim()
}

function truncate(text: string, max = 200): string {
  if (text.length <= max) {
    return text
  }

  const cut = text.lastIndexOf(' ', max)

  return (cut > 0 ? text.slice(0, cut) : text.slice(0, max)) + ' …'
}

export function bookPayload(book: Book): Record<string, unknown> {
  return {
    scraper: book.scraper,
    scrap_id: book.scrapId,
    title: book.title,
    cover: book.cover,
    author: book.author,
    narrators: book.narrators,
    runtime: book.runtime,
    ratings: book.ratings,
    saga: book.saga?.name || '',
    tome: book.saga?.tome || '',
  }
}

export async function updateBook(id: string, book: Book): Promise<Book> {
  const result = await fetch(`${API_BASE_URL}/api/books/${encodeURIComponent(id)}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(bookPayload(book)),
  })
  const data = await result.json().catch(() => null)

  if (result.status !== 200) {
    throw new Error(data?.error || `Échec de la mise à jour (${result.status})`)
  }

  return bookFromApi(data) || book
}

// Détails d'une erreur JS pour le rapport Discord : message + « fichier:ligne » de
// la première frame de la pile (le site du déclenchement).
function errorDetails(error: unknown): { error: string; source: string } {
  if (!(error instanceof Error)) {
    return { error: error === undefined || error === null ? '' : String(error), source: '' }
  }

  const frame = (error.stack ?? '')
    .split('\n')
    .map((line) => line.trim())
    .find((line) => line.startsWith('at '))

  // « at uploadBook (http://hôte/src/api/book.ts:186:17) » → « src/api/book.ts:186 »
  const match = frame?.match(/([^\s()]+):(\d+):\d+\)?\s*$/)

  return {
    error: error.message,
    source: match ? `${match[1].replace(/^https?:\/\/[^/]+\//, '')}:${match[2]}` : '',
  }
}

// Signale un envoi coupé : le backend poste un message Discord orienté vers les
// fichiers orphelins pour corriger le livre au plus vite. L'erreur d'upload
// (message + fichier:ligne) accompagne le signalement.
export async function reportUploadCut(book: Book, error?: unknown): Promise<void> {
  const details = errorDetails(error)

  const result = await fetch(`${API_BASE_URL}/api/report-upload-cut`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ...bookPayload(book), error: details.error, source: details.source }),
  })

  if (result.status !== 204) {
    throw new Error(`Échec du signalement (${result.status})`)
  }
}
