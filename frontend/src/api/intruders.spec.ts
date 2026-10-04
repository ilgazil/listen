import { afterEach, describe, expect, it, vi } from 'vitest'
import { deleteIntruder, fetchIntruders } from '@/api/intruders.ts'

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('fetchIntruders', () => {
  it('mappe le contrat API vers des livres', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(
        JSON.stringify([
          {
            id: 'ghost1',
            scraper: 'audible',
            scrap_id: 'B0001',
            title: 'Le Livre Fantôme',
            cover: 'https://example.com/c.jpg',
            author: 'Un Auteur',
            narrators: ['Un Narrateur'],
            runtime: '10:00:00',
            ratings: 4.5,
            saga: 'Une Saga',
            tome: '1',
          },
        ]),
      ),
    )

    const intruders = await fetchIntruders()

    expect(intruders).toHaveLength(1)
    expect(intruders[0].id).toBe('ghost1')
    expect(intruders[0].title).toBe('Le Livre Fantôme')
    expect(intruders[0].saga).toMatchObject({ name: 'Une Saga', tome: '1' })
  })

  it('retourne une liste vide si la réponse est un 200 sans tableau', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({})))

    expect(await fetchIntruders()).toEqual([])
  })

  it('lève une erreur avec le message du serveur si le statut n’est pas 200', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ error: 'Unable to list stored files.' }), { status: 502 }),
    )

    await expect(fetchIntruders()).rejects.toThrow('Unable to list stored files.')
  })
})

describe('deleteIntruder', () => {
  it('réussit silencieusement sur un 204', async () => {
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValue(new Response(null, { status: 204 }))

    await deleteIntruder('ghost1')

    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('/api/intruders/ghost1')
    expect(init?.method).toBe('DELETE')
  })

  it('lève une erreur avec le message du serveur si le statut n’est pas 204', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ error: 'This file is still present on 1Fichier.' }), {
        status: 409,
      }),
    )

    await expect(deleteIntruder('ghost1')).rejects.toThrow('This file is still present on 1Fichier.')
  })
})