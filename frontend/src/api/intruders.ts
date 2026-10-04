import { deleteItem, fetchTable } from '@/api/core.ts'
import { bookCollectionFromApi } from '@/api/book.ts'
import { Book } from '@/entities/book.ts'

export type Intruder = Book

export async function fetchIntruders(): Promise<Array<Intruder>> {
  return fetchTable('/api/intruders', 'des intrus', bookCollectionFromApi)
}

export async function deleteIntruder(id: string): Promise<void> {
  return deleteItem(`/api/intruders/${encodeURIComponent(id)}`)
}