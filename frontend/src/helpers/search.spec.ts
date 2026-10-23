import { describe, expect, it } from 'vitest'
import { filenameToSearchTerm } from '@/helpers/search.ts'

describe('filenameToSearchTerm', () => {
  it('strips extension, separators, tome markers and determinants', () => {
    expect(filenameToSearchTerm("J.K. Rowling - Harry Potter 1 - A l'école des sorciers.zip")).toBe(
      "Rowling Harry Potter l'école sorciers",
    )
  })

  it('strips quality tags and tome markers', () => {
    expect(filenameToSearchTerm('harry-potter-tome-1-v2-144kbps.zip')).toBe('harry potter')
  })

  it('turns underscores into word separators', () => {
    expect(filenameToSearchTerm('Dune_Frank_Herbert.zip')).toBe('Dune Frank Herbert')
  })

  it('keeps numbers with 3+ digits (years, titles)', () => {
    expect(filenameToSearchTerm('1984 - George Orwell.zip')).toBe('1984 George Orwell')
  })

  it('drops 1-2 digits numbers (tome numbers)', () => {
    expect(filenameToSearchTerm('guerre et paix 2.zip')).toBe('guerre paix')
  })

  it('falls back to the raw base name when everything is filtered out', () => {
    expect(filenameToSearchTerm('t2.zip')).toBe('t2')
    expect(filenameToSearchTerm('le.zip')).toBe('le')
  })
})
