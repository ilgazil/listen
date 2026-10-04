import { bookPayload } from '@/api/book.ts'
import { deleteItem, fetchTable, mapArray, mapObject } from '@/api/core.ts'
import { API_BASE_URL } from '@/api/config.ts'
import { Book } from '@/entities/book.ts'

export interface Orphan {
  id: string
  name: string
  size: number
  date: string
}

function orphanFromApi(data: unknown): Orphan | undefined {
  const source = mapObject(data)
  if (!source) {
    return
  }

  return {
    id: String(source.id || ''),
    name: String(source.name || ''),
    size: Number(source.size) || 0,
    date: String(source.date || ''),
  }
}

function orphanCollectionFromApi(data: unknown): Array<Orphan> {
  return (
    mapArray(data)
      ?.map(orphanFromApi)
      .filter((orphan): orphan is Orphan => Boolean(orphan)) || []
  )
}

export async function fetchOrphans(): Promise<Array<Orphan>> {
  return fetchTable('/api/orphans', 'des orphelins', orphanCollectionFromApi)
}

export async function fixOrphan(id: string, book: Book): Promise<Book> {
  const result = await fetch(`${API_BASE_URL}/api/orphans/${encodeURIComponent(id)}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(bookPayload(book)),
  })
  const data = await result.json().catch(() => null)

  if (result.status !== 201) {
    throw new Error(data?.error || `Échec de l'enregistrement (${result.status})`)
  }

  return book
}

export async function deleteOrphan(id: string): Promise<void> {
  return deleteItem(`/api/orphans/${encodeURIComponent(id)}`)
}
