import { API_BASE_URL } from '@/api/config.ts'

export function mapArray<T = unknown>(value: unknown): T[] | undefined {
  if (Array.isArray(value)) {
    return value
  }

  return undefined
}

export function mapObject(value: unknown): Record<string | number | symbol, unknown> | undefined {
  return typeof value === 'object' && value ? Object.fromEntries(Object.entries(value)) : undefined
}

export function mapIn<T>(value: unknown, list: T[]): T | undefined {
  return value && list.includes(value as T) ? (value as T) : undefined
}

export async function fetchTable<T>(
  path: string,
  label: string,
  mapper: (data: unknown) => Array<T>,
): Promise<Array<T>> {
  const result = await fetch(`${API_BASE_URL}${path}`)
  const data = await result.json().catch(() => null)

  if (result.status !== 200) {
    throw new Error(data?.error || `Échec de la récupération ${label} (${result.status})`)
  }

  return mapper(data)
}

export async function deleteItem(path: string): Promise<void> {
  const result = await fetch(`${API_BASE_URL}${path}`, { method: 'DELETE' })

  if (result.status !== 204) {
    const data = await result.json().catch(() => null)
    throw new Error(data?.error || `Échec de la suppression (${result.status})`)
  }
}
