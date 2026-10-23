// Dérive un terme de recherche depuis un nom de fichier ZIP : l'extension, les
// séparateurs, les marqueurs de tome (« tome 1 », « t2 »), les tags de qualité
// (« mp3 », « 144kbps », « v2 ») et les déterminants parasitent la recherche de
// métadonnées s'ils sont envoyés tels quels.
// L'apostrophe n'est pas un séparateur : « l'école » reste un seul token lisible
// (le backend normalise les apostrophes de toute façon).
const SEPARATORS = /[._\-\[\](){};,!?:"«»–—]+/g
const TOME_MARKERS = /^(t|tome|vol|volume|partie|part|livre|book)\d*([.,]\d+)?$/i
const QUALITY_TAGS = /^(mp3|m4a|m4b|mp4|flac|ogg|aac|wav|audiobook|\d+kbps|1fichier|french|vf|v\d+)$/i
const DETERMINANTS = /^(le|la|les|l|d|un|une|du|de|des|au|aux|et|the|of|and)$/i

export function filenameToSearchTerm(name: string): string {
  const base = name.replace(/\.[^.]+$/, '')

  const tokens = base
    .replace(SEPARATORS, ' ')
    .split(/\s+/)
    .filter((token) => token.length > 1)
    .filter((token) => !TOME_MARKERS.test(token))
    .filter((token) => !QUALITY_TAGS.test(token))
    .filter((token) => !DETERMINANTS.test(token))
    .filter((token) => !/^\d{1,2}([.,]\d+)?$/.test(token))

  // Rien ne survit (ex. « t2.zip ») : on retombe sur le nom brut sans extension.
  return tokens.join(' ') || base
}
