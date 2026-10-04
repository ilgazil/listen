import { afterEach, describe, expect, it, vi } from 'vitest'
import { deleteOrphan, fetchOrphans, fixOrphan } from '@/api/orphans.ts'

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('fetchOrphans', () => {
  it('mappe le contrat API vers des orphelins', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(
        JSON.stringify([
          { id: 'abc123', name: 'orphelin-a.zip', size: 1024, date: 1000 },
          { id: 'def456', name: 'orphelin-b.zip', size: 2048, date: 2000 },
        ]),
      ),
    )

    const orphans = await fetchOrphans()

    expect(orphans).toEqual([
      { id: 'abc123', name: 'orphelin-a.zip', size: 1024, date: '1000' },
      { id: 'def456', name: 'orphelin-b.zip', size: 2048, date: '2000' },
    ])
  })

  it('retourne une liste vide si la réponse est un 200 sans tableau', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({})))

    expect(await fetchOrphans()).toEqual([])
  })

  it('lève une erreur avec le message du serveur si le statut n’est pas 200', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ error: 'Unable to list orphan files.' }), { status: 502 }),
    )

    await expect(fetchOrphans()).rejects.toThrow('Unable to list orphan files.')
  })
})

describe('fixOrphan', () => {
  it('envoie le payload du livre et retourne le Book', async () => {
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValue(new Response(JSON.stringify({ id: 'abc123' }), { status: 201 }))

    const { Book } = await import('@/entities/book.ts')
    const book = new Book()
    book.title = 'Livre orphelin'
    book.ratings = 4.6

    const result = await fixOrphan('abc123', book)

    expect(result).toBe(book)

    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('/api/orphans/abc123')
    expect(init?.method).toBe('POST')
    expect(init?.headers).toEqual({ 'Content-Type': 'application/json' })
    expect(JSON.parse(init?.body as string)).toMatchObject({
      title: 'Livre orphelin',
      ratings: 4.6,
    })
  })

  it('lève une erreur avec le message du serveur si le statut n’est pas 201', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ error: 'Orphan file not found.' }), { status: 404 }),
    )

    const { Book } = await import('@/entities/book.ts')

    await expect(fixOrphan('unknown', new Book())).rejects.toThrow('Orphan file not found.')
  })
})

describe('deleteOrphan', () => {
  it('réussit silencieusement sur un 204', async () => {
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValue(new Response(null, { status: 204 }))

    await deleteOrphan('abc123')

    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('/api/orphans/abc123')
    expect(init?.method).toBe('DELETE')
  })

  it('lève une erreur avec le message du serveur si le statut n’est pas 204', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ error: 'Orphan file not found.' }), { status: 404 }),
    )

    await expect(deleteOrphan('unknown')).rejects.toThrow('Orphan file not found.')
  })
})
