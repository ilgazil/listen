<?php

namespace App\Service\Scraping\Audible;

use App\Entity\Book;
use App\Service\Scraping\Fetcher;
use App\Service\Scraping\ScraperInterface;
use App\Service\Scraping\SearchRanker;
use Throwable;

class AudibleScraper implements ScraperInterface
{
    public static string $NAME = 'audible';

    /**
     * Enrichissement série : déclenché dès qu'une saga dont le **nom matche la
     * recherche** est présente dans les résultats (le score exige tous les mots du
     * pattern dans le nom de saga). La page série liste la saga complète, là où la
     * recherche n'expose que ~20 produits.
     */
    protected const MIN_SERIES_SCORE = 60;

    /** Nb max de pages série scrapées par recherche (les meilleures d'abord). */
    protected const MAX_SERIES_ENRICHMENTS = 2;

    /** Audible renvoie souvent une 503 sur les pages série : on retente. */
    protected const MAX_SERIES_ATTEMPTS = 3;
    protected const SERIES_RETRY_DELAY = 1;

    protected string $endpoint = 'https://www.audible.fr/';

    protected array $baseUrlQuery = [
        'ipRedirectOverride' => 'true',
        'overrideBaseCountry' => 'true',
    ];

    public function __construct(protected AudibleSeriesCache $seriesCache)
    {
    }

    public function search(string $pattern): array
    {
        // Normalisation partagée (accents, ponctuation, casse) — le pattern n'est plus
        // pré-encodé : buildUrl s'en charge (l'ancien double-encodage produisait %2B).
        $url = (new \anlutro\cURL\cURL())->buildUrl(
            $this->endpoint . 'search',
            ['keywords' => SearchRanker::normalize($pattern)] + $this->baseUrlQuery,
        );

        $parser = new AudibleSearchParser($this->fetch($url));
        $books = $parser->getBooks();

        return $this->withSeriesBooks(SearchRanker::normalize($pattern), $books, $parser);
    }

    /**
     * Complète les sagas tronquées : une recherche sur le nom d'une saga n'expose que
     * ~20 produits et peut occulter des tomes (ex. « bourbon kid » → 10 tomes sur 11).
     * Dès qu'une saga dont le nom matche la recherche est présente sur la page (lien
     * « Série : … »), sa page série — qui liste la saga entière — est scrapée et les
     * livres fusionnés par ASIN.
     *
     * @param array<Book> $books
     * @return array<Book>
     */
    protected function withSeriesBooks(string $pattern, array $books, AudibleSearchParser $parser): array
    {
        try {
            $seriesPaths = $this->detectMatchingSeries($pattern, $parser->getSeriesUrls());

            if (!$seriesPaths) {
                return $books;
            }

            $seriesBooks = [];

            foreach ($seriesPaths as $seriesPath) {
                $seriesBooks = array_merge(
                    $seriesBooks,
                    (new AudibleSearchParser($this->fetchSeriesPage($seriesPath)))->getBooks(false),
                );
            }

            // Dédup par ASIN : la version avec narrateurs prime, sinon la première vue.
            $byAsin = [];

            foreach (array_merge($books, $seriesBooks) as $book) {
                $asin = $book->getScrapId();

                if ($asin !== '' && (!isset($byAsin[$asin]) || (!$byAsin[$asin]->getNarrators() && $book->getNarrators()))) {
                    $byAsin[$asin] = $book;
                }
            }

            return array_values($byAsin);
        } catch (Throwable) {
            // L'enrichissement série est un bonus : en cas d'échec (réseau, anti-bot,
            // markup changé), la recherche seule est renvoyée.
            return $books;
        }
    }

    /**
     * Page série, avec retries (Audible renvoie régulièrement sa 503 anti
     * rate-limiting) et cache quotidien — une page obtenue n'est plus redemandée.
     */
    protected function fetchSeriesPage(string $seriesPath): string
    {
        $seriesId = basename((string) parse_url($seriesPath, PHP_URL_PATH));

        $cached = $this->seriesCache->get($seriesId);

        if ($cached !== null) {
            return $cached;
        }

        for ($attempt = 1; ; $attempt++) {
            $html = $this->fetch($this->endpoint . ltrim($seriesPath, '/'));

            if (str_contains($html, 'productListItem')) {
                $this->seriesCache->set($seriesId, $html);

                return $html;
            }

            if ($attempt >= self::MAX_SERIES_ATTEMPTS) {
                // Échec : le parseur n'en tirera rien, l'appelant retombe sur la recherche.
                return $html;
            }

            sleep(self::SERIES_RETRY_DELAY);
        }
    }

    /**
     * Pages série des sagas dont le nom matche la recherche (tous les mots du pattern
     * dans le nom de saga — score ≥ MIN_SERIES_SCORE), les meilleures d'abord.
     *
     * @param array<string, string> $seriesUrls nom de saga → chemin de la page série
     * @return array<string> chemins des pages série à scraper
     */
    protected function detectMatchingSeries(string $pattern, array $seriesUrls): array
    {
        $scores = [];

        foreach ($seriesUrls as $saga => $seriesUrl) {
            $score = SearchRanker::score($pattern, $saga);

            if ($score >= self::MIN_SERIES_SCORE) {
                $scores[$saga] = $score;
            }
        }

        arsort($scores);

        return array_slice(array_map(fn(string $saga) => $seriesUrls[$saga], array_keys($scores)), 0, self::MAX_SERIES_ENRICHMENTS);
    }

    public function get(string $code): Book|null
    {
        $body = $this->fetch($this->endpoint . 'pd/Book/' . $code);

        if (empty($body)) {
            return null;
        }

        return (new AudibleBookParser($body))->createBook();
    }

    protected function fetch(string $url): string
    {
        return Fetcher::get($url);
    }
}
