<?php

namespace App\Service\Scraping;

use App\Entity\Book;

/**
 * Outils de recherche partagés par les scrapers et le contrôleur /api/scrap :
 * normalisation multibyte (accents, apostrophes, ponctuation), scoring de pertinence
 * et fusion scorée des résultats des sources.
 */
final class SearchRanker
{
    /** Plafond de la fusion finale (toutes sources confondues). */
    public const MAX_RESULTS = 30;

    /**
     * Normalise un texte pour la recherche : translittération Latin-ASCII (accents),
     * minuscules, tout caractère non alphanumérique → espace, espaces réduites.
     *
     * Multibyte sûr — l'ancienne normalisation (\W sans flag u) scindait les
     * caractères accentués en octets et les remplaçait par des espaces.
     */
    public static function normalize(string $value): string
    {
        $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII');

        if ($transliterator !== null) {
            $value = $transliterator->transliterate($value) ?? $value;
        }

        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /**
     * Mots du pattern après normalisation. Les mots d'un seul caractère sont écartés
     * (bruit de « l' », « d' », déterminants), sauf s'ils sont les seuls restants.
     *
     * @return array<string>
     */
    public static function words(string $pattern): array
    {
        $words = array_values(array_filter(explode(' ', self::normalize($pattern))));
        $words = array_values(array_filter($words, fn(string $word) => mb_strlen($word) > 1));

        if ($words) {
            return $words;
        }

        // Tous les mots faisaient un seul caractère (initiales « j k ») : on les garde.
        return array_values(array_filter(explode(' ', self::normalize($pattern))));
    }

    /**
     * Score de pertinence d'un livre pour un pattern (0 = hors sujet).
     *
     * Hiérarchie : titre égal (100) > commence par (90) > contient la phrase (80) >
     * tous les mots dans le titre (60) > tous les mots répartis titre/auteur (40) >
     * tous les mots dans la saga (35) > correspondances partielles pondérées.
     */
    public static function score(string $pattern, string $title, string $author = '', string $saga = ''): int
    {
        $pattern = self::normalize($pattern);

        if ('' === $pattern) {
            return 0;
        }

        $title = self::normalize($title);
        $author = self::normalize($author);
        $saga = self::normalize($saga ?? '');

        $score = 0;

        if ($title === $pattern) {
            $score = 100;
        } elseif (str_starts_with($title, $pattern)) {
            $score = 90;
        } elseif (str_contains($title, $pattern)) {
            $score = 80;
        }

        $words = self::words($pattern);
        $inTitle = 0;
        $inAuthor = 0;
        $inSaga = 0;

        foreach ($words as $word) {
            $inTitle += str_contains($title, $word) ? 1 : 0;
            $inAuthor += str_contains($author, $word) ? 1 : 0;
            $inSaga += str_contains($saga, $word) ? 1 : 0;
        }

        $total = count($words);

        if ($total && $inTitle === $total) {
            $score = max($score, 60);
        } elseif ($total && $inTitle + $inAuthor === $total) {
            $score = max($score, 40);
        }

        if ($total && $inSaga === $total) {
            $score = max($score, 35);
        }

        // Correspondances partielles : chaque mot compte, le titre pèse plus que l'auteur.
        // Les mots très courts (« des », « aux »…) sont ignorés ici : ils n'apportent rien
        // en partiel et noieraient les résultats de faux positifs.
        $partial = 0;

        foreach ($words as $word) {
            if (mb_strlen($word) > 3) {
                $partial += str_contains($title, $word) ? 10 : (str_contains($author, $word) ? 5 : (str_contains($saga, $word) ? 2 : 0));
            }
        }

        return max($score, $partial);
    }

    /**
     * Fusionne les résultats des sources : score, dédup (même titre + auteur),
     * tri par pertinence décroissante, plafond global. Les résultats sans score
     * sont conservés en fin de liste (on ne perd jamais un résultat de scraper).
     *
     * @param array<Book> ...$groups groupes dans l'ordre de priorité (dédup à score égal)
     * @return array<Book>
     */
    public static function merge(string $pattern, array ...$groups): array
    {
        $best = [];

        foreach ($groups as $group) {
            foreach ($group as $book) {
                $key = self::normalize($book->getTitle()) . '|' . self::normalize($book->getAuthor());
                $score = self::score($pattern, $book->getTitle(), $book->getAuthor(), $book->getSaga() ?? '');

                // À score égal, on garde le premier (ordre des groupes), sauf si le nouveau
                // porte une saga (métadonnées plus riches pour organiser la bibliothèque).
                if (!isset($best[$key]) || $score > $best[$key]['score'] || ($score === $best[$key]['score'] && $book->getSaga() && !$best[$key]['book']->getSaga())) {
                    $best[$key] = ['score' => $score, 'book' => $book];
                }
            }
        }

        usort($best, fn(array $a, array $b) => $b['score'] <=> $a['score']);

        return array_slice(array_map(fn(array $entry) => $entry['book'], $best), 0, self::MAX_RESULTS);
    }
}
