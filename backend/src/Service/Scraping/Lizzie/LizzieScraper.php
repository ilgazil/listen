<?php

namespace App\Service\Scraping\Lizzie;

use App\Entity\Book;
use App\Service\Scraping\ScraperInterface;
use App\Service\Scraping\SearchRanker;

class LizzieScraper implements ScraperInterface
{
    public static string $NAME = 'lizzie';

    /** Plafond interne : la fusion finale (SearchRanker) retraite ensuite les résultats. */
    protected const MAX_RESULTS = 50;

    /**
     * Score minimal pour retenir un livre du catalogue : en dessous (correspondance
     * partielle sur un seul mot distinctif), le résultat n'est que du bruit.
     */
    protected const MIN_SCORE = 20;

    public function __construct(protected LizzieCache $cache)
    {
    }

    public function search(string $pattern): array
    {
        if ('' === SearchRanker::normalize($pattern)) {
            return [];
        }

        $matches = [];

        foreach ($this->cache->getLibrary() as $item) {
            // Matching tolérant : titres/auteurs normalisés comme le pattern (accents,
            // apostrophes, ponctuation) et mots indépendants — « l etranger » trouve
            // « L'Étranger », l'ordre des mots importe peu.
            $score = SearchRanker::score($pattern, $item->getTitle(), $item->getAuthor());

            if ($score >= self::MIN_SCORE) {
                $matches[] = ['score' => $score, 'item' => $item];
            }
        }

        // Les meilleurs scores d'abord (avant : les 20 premiers dans l'ordre du catalogue).
        usort($matches, fn(array $a, array $b) => $b['score'] <=> $a['score']);
        $matches = array_slice($matches, 0, self::MAX_RESULTS);

        return array_map(
            fn(array $match) => $match['item']->toBook(),
            $matches,
        );
    }

    public function get(string $code): Book|null
    {
        foreach ($this->cache->getLibrary() as $item) {
            if ($item->getScrapId() === $code) {
                return $item->toBook();
            }
        }

        return null;
    }

    /**
     * Première passe : le scrap d'URL Lizzie n'est pas câblé — une URL
     * lizzie.audio retombe sur la recherche classique (null).
     */
    public function fromUrl(string $url): Book|null
    {
        return null;
    }
}
