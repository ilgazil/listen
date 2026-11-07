<?php

namespace App\Tests\Service\Scraping\Audible;

use App\Service\Scraping\Audible\AudibleScraper;
use App\Service\Scraping\Audible\AudibleSeriesCache;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AudibleScraperTest extends TestCase
{
    private const string SEARCH_FIXTURE = __DIR__ . '/../../../Fixtures/audible-bourbon-search.html';
    private const string SERIES_FIXTURE = __DIR__ . '/../../../Fixtures/audible-series.html';
    private const string SCARLETT_FIXTURE = __DIR__ . '/../../../Fixtures/audible-search.html';
    private const string BOOK_FIXTURE = __DIR__ . '/../../../Fixtures/audible-book.html';
    private const string TOWERS_SERIES_FIXTURE = __DIR__ . '/../../../Fixtures/audible-series-towers.html';

    public function testSearchEnrichesIncompleteSeriesFromSeriesPage(): void
    {
        if (!is_file(self::SEARCH_FIXTURE) || !is_file(self::SERIES_FIXTURE)) {
            self::markTestSkipped('fixtures audible-bourbon-search.html / audible-series.html non présentes (gitignorées).');
        }

        $scraper = $this->scraper(function (string $url): string {
            // La page de recherche déclenche la détection de saga, la page série la complète.
            return str_contains($url, '/series/')
                ? (string) file_get_contents(self::SERIES_FIXTURE)
                : (string) file_get_contents(self::SEARCH_FIXTURE);
        });

        $books = $scraper->search('bourbon kid');

        // La saga complète (11 livres) est renvoyée, le tome absent de la recherche inclus.
        $tomes = array_map(fn($book) => $book->getTome(), $books);

        foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11'] as $tome) {
            self::assertContains($tome, $tomes, "le tome $tome manque");
        }

        // Dédup par ASIN : aucun livre en double.
        $asins = array_map(fn($book) => $book->getScrapId(), $books);

        self::assertSame($asins, array_unique($asins));

        $tome8 = $books[array_search('8', $tomes)];

        self::assertSame("Que le diable l'emporte", $tome8->getTitle());
    }

    public function testSearchWithoutSeriesReturnsSearchResultsOnly(): void
    {
        if (!is_file(self::SCARLETT_FIXTURE)) {
            self::markTestSkipped('fixture audible-search.html non présente (gitignorée).');
        }

        // Page synthétique : deux livres de sagas distinctes (aucune saga ≥ 3 livres)
        // → pas d'appel à une page série.
        preg_match_all('/productListItem.+bc-spacing-top-base.+<\/li/msU', (string) file_get_contents(self::SCARLETT_FIXTURE), $blocks);
        $searchPage = implode('', array_slice($blocks[0], 0, 2));

        $urls = [];

        $scraper = $this->scraper(function (string $url) use (&$urls, $searchPage): string {
            $urls[] = $url;

            return $searchPage;
        });

        $books = $scraper->search('anything');

        self::assertCount(2, $books);
        self::assertCount(1, $urls);
    }

    public function testSingleSagaMatchTriggersSeriesEnrichment(): void
    {
        if (!is_file(self::SEARCH_FIXTURE) || !is_file(self::SERIES_FIXTURE)) {
            self::markTestSkipped('fixtures audible-bourbon-search.html / audible-series.html non présentes (gitignorées).');
        }

        // Un seul livre de la saga dans les résultats suffit : si le nom de la saga
        // matche la recherche, la page série complète est ajoutée.
        preg_match_all('/productListItem.+bc-spacing-top-base.+<\/li/msU', (string) file_get_contents(self::SEARCH_FIXTURE), $blocks);
        $singleBookPage = $blocks[0][0];

        $scraper = $this->scraper(function (string $url) use ($singleBookPage): string {
            return str_contains($url, '/series/')
                ? (string) file_get_contents(self::SERIES_FIXTURE)
                : $singleBookPage;
        });

        $books = $scraper->search('bourbon kid');

        $tomes = array_map(fn($book) => $book->getTome(), $books);

        self::assertContains('8', $tomes);
        self::assertContains('11', $tomes);
    }

    public function testPartiallyMatchingSagaDoesNotTriggerEnrichment(): void
    {
        if (!is_file(self::SEARCH_FIXTURE)) {
            self::markTestSkipped('fixture audible-bourbon-search.html non présente (gitignorée).');
        }

        // Saga « Bourbon Canyon » : seul « bourbon » matche le pattern « bourbon kid »
        // (score partiel < seuil) → pas de page série.
        preg_match_all('/productListItem.+bc-spacing-top-base.+<\/li/msU', (string) file_get_contents(self::SEARCH_FIXTURE), $blocks);
        $searchPage = str_replace('Bourbon Kid', 'Bourbon Canyon', $blocks[0][0]);

        $urls = [];

        $scraper = $this->scraper(function (string $url) use (&$urls, $searchPage): string {
            $urls[] = $url;

            return $searchPage;
        });

        $books = $scraper->search('bourbon kid');

        self::assertCount(1, $books);
        self::assertCount(1, $urls);
    }

    public function testSeriesFailureFallsBackToSearchResults(): void
    {
        if (!is_file(self::SEARCH_FIXTURE)) {
            self::markTestSkipped('fixture audible-bourbon-search.html non présente (gitignorée).');
        }

        $scraper = $this->scraper(function (string $url): string {
            if (str_contains($url, '/series/')) {
                // Ex. 503 anti rate-limiting d'Audible.
                throw new \RuntimeException('Audible indisponible');
            }

            return (string) file_get_contents(self::SEARCH_FIXTURE);
        });

        $books = $scraper->search('bourbon kid');

        self::assertNotEmpty($books);
        self::assertNotContains('8', array_map(fn($book) => $book->getTome(), $books));
    }

    public function testSeriesPageWith503BodyIsRetriedThenSucceeds(): void
    {
        if (!is_file(self::SEARCH_FIXTURE) || !is_file(self::SERIES_FIXTURE)) {
            self::markTestSkipped('fixtures audible-bourbon-search.html / audible-series.html non présentes (gitignorées).');
        }

        // Audible renvoie parfois sa page d'erreur (503, ~2 Ko) sans faire
        // échouer le transfert : on retente jusqu'à obtenir la page série.
        $errorPage = '<html><body>Service Unavailable</body></html>';
        $calls = 0;

        $scraper = $this->scraper(function (string $url) use (&$calls, $errorPage): string {
            $calls++;

            if (str_contains($url, '/series/')) {
                return $calls >= 3 ? (string) file_get_contents(self::SERIES_FIXTURE) : $errorPage;
            }

            return (string) file_get_contents(self::SEARCH_FIXTURE);
        });

        $books = $scraper->search('bourbon kid');

        self::assertContains('8', array_map(fn($book) => $book->getTome(), $books));
    }

    public function testSearchRelaxesPatternWhenStrictSearchFindsNothing(): void
    {
        if (!is_file(self::SEARCH_FIXTURE) || !is_file(self::SERIES_FIXTURE)) {
            self::markTestSkipped('fixtures audible-bourbon-search.html / audible-series.html non présentes (gitignorées).');
        }

        // Recherche Audible = ET strict : un blob « auteur saga titre » (« anonyme
        // bourbon kid cimetiere diable ») ne renvoie rien. On relâche : sans le
        // premier mot, puis la queue du titre (« cimetiere diable »).
        $keywordsSeen = [];

        $scraper = $this->scraper(function (string $url) use (&$keywordsSeen): string {
            // Page série (pas de keywords dans l'URL) → fixture série.
            if (str_contains($url, '/series/')) {
                return (string) file_get_contents(self::SERIES_FIXTURE);
            }

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $keywords = (string) ($query['keywords'] ?? '');
            $keywordsSeen[] = $keywords;

            // Page « vide » légitime : grande, sans produit (pas une 503).
            $empty = '<html><body>' . str_repeat('x', 20000) . '</body></html>';

            return str_word_count(str_replace('_', ' ', $keywords)) > 2 ? $empty : (string) file_get_contents(self::SEARCH_FIXTURE);
        });

        $books = $scraper->search('anonyme bourbon kid cimetiere diable');

        // La queue du titre a été essayée en dernier recours…
        self::assertContains('cimetiere diable', $keywordsSeen);
        // … et l'enrichissement série a complété la saga.
        self::assertContains('8', array_map(fn($book) => $book->getTome(), $books));
    }

    public function testSeriesEnrichmentTriggersWhenPatternContainsSagaName(): void
    {
        if (!is_file(self::SEARCH_FIXTURE) || !is_file(self::SERIES_FIXTURE)) {
            self::markTestSkipped('fixtures audible-bourbon-search.html / audible-series.html non présentes (gitignorées).');
        }

        // Un seul livre de la saga dans les résultats, pattern = blob contenant le nom
        // de la saga : l'enrichissement doit quand même se déclencher (match inversé).
        preg_match_all('/productListItem.+bc-spacing-top-base.+<\/li/msU', (string) file_get_contents(self::SEARCH_FIXTURE), $blocks);
        $singleBookPage = $blocks[0][0];

        $scraper = $this->scraper(function (string $url) use ($singleBookPage): string {
            return str_contains($url, '/series/')
                ? (string) file_get_contents(self::SERIES_FIXTURE)
                : $singleBookPage;
        });

        $books = $scraper->search('anonyme bourbon kid cimetiere diable');

        $tomes = array_map(fn($book) => $book->getTome(), $books);

        self::assertContains('7', $tomes);
        self::assertContains('8', $tomes);
        self::assertContains('11', $tomes);
    }

    public function testSeriesPageIsCachedForTheDay(): void
    {
        if (!is_file(self::SEARCH_FIXTURE) || !is_file(self::SERIES_FIXTURE)) {
            self::markTestSkipped('fixtures audible-bourbon-search.html / audible-series.html non présentes (gitignorées).');
        }

        $cacheDir = sys_get_temp_dir() . '/audible-series-cache-' . uniqid();
        $seriesCalls = 0;

        // Closure classique (pas de fn) : les références & bindent la portée du test.
        $make = function () use ($cacheDir, &$seriesCalls) {
            return $this->scraper(function (string $url) use (&$seriesCalls): string {
                if (str_contains($url, '/series/')) {
                    $seriesCalls++;

                    return (string) file_get_contents(self::SERIES_FIXTURE);
                }

                return (string) file_get_contents(self::SEARCH_FIXTURE);
            }, $cacheDir);
        };

        ($make())->search('bourbon kid');
        ($make())->search('bourbon kid');

        // Deux recherches successives : la page série n'est demandée qu'une fois.
        self::assertSame(1, $seriesCalls);

        array_map(unlink(...), glob($cacheDir . '/*') ?: []);
        @rmdir($cacheDir);
    }

    public function testGetParsesCurrentBookPage(): void
    {
        if (!is_file(self::BOOK_FIXTURE)) {
            self::markTestSkipped('fixture audible-book.html non présente (gitignorée).');
        }

        $scraper = $this->scraper(fn (string $url): string => (string) file_get_contents(self::BOOK_FIXTURE));

        $book = $scraper->get('B01M2WSG6T');

        self::assertNotNull($book);
        self::assertSame("L'apprenti assassin", $book->getTitle());
        self::assertSame('Robin Hobb', $book->getAuthor());
        self::assertSame(['Sylvain Agaësse'], $book->getNarrators());
        self::assertSame('17:48', $book->getRuntime());
        self::assertSame(4.8, $book->getRatings());
        self::assertSame("L'Assassin Royal", $book->getSaga());
        self::assertSame('1', $book->getTome());
        self::assertSame('B01M2WSG6T', $book->getScrapId());
    }

    public function testGetReturnsNullOnSmallErrorPage(): void
    {
        // Mur 503 (~2 Ko) épuisé après retries : jamais de livre fantôme à partir
        // d'une page d'erreur (le re-scrap CLI proposerait d'écraser les champs).
        $errorPage = '<html><body>Service Unavailable</body></html>';

        $scraper = $this->scraper(fn (): string => $errorPage);

        self::assertNull($scraper->get('B01M2WSG6T'));
    }

    public function testGetReturnsNullOnUnavailableTitlePage(): void
    {
        // Page « titre indisponible » (livre retiré du catalogue) : une vraie page,
        // grande, mais sans ASIN canonique ni data-asin → pas un livre.
        $page = '<html><body><h1 slot="title">Désolés ! Ce titre n&#39;est pas disponible.</h1>'
            . str_repeat('x', 20000)
            . '</body></html>';

        $scraper = $this->scraper(fn (): string => $page);

        self::assertNull($scraper->get('B000000000'));
    }

    public function testSeriesPageWithCurrentMarkupIsCachedForTheDay(): void
    {
        if (!is_file(self::SEARCH_FIXTURE) || !is_file(self::TOWERS_SERIES_FIXTURE)) {
            self::markTestSkipped('fixtures audible-bourbon-search.html / audible-series-towers.html non présentes (gitignorées).');
        }

        // Les pages série au markup actuel (rangées adbl-product-row) sont mises en
        // cache comme les anciennes.
        $cacheDir = sys_get_temp_dir() . '/audible-series-cache-' . uniqid();
        $seriesCalls = 0;

        $make = function () use ($cacheDir, &$seriesCalls) {
            return $this->scraper(function (string $url) use (&$seriesCalls): string {
                if (str_contains($url, '/series/')) {
                    $seriesCalls++;

                    return (string) file_get_contents(self::TOWERS_SERIES_FIXTURE);
                }

                return (string) file_get_contents(self::SEARCH_FIXTURE);
            }, $cacheDir);
        };

        ($make())->search('bourbon kid');
        ($make())->search('bourbon kid');

        self::assertSame(1, $seriesCalls);

        array_map(unlink(...), glob($cacheDir . '/*') ?: []);
        @rmdir($cacheDir);
    }

    /**
     * Scraper avec fetch piloté par un handler (équivalent d'un mock, sans magie
     * PHPUnit) et cache de série isolé.
     */
    private function scraper(callable $fetch, ?string $cacheDir = null): AudibleScraper
    {
        return new class(new AudibleSeriesCache($cacheDir ?? (sys_get_temp_dir() . '/audible-series-cache-' . uniqid())), $fetch) extends AudibleScraper {
            public function __construct(AudibleSeriesCache $cache, private $fetcher)
            {
                parent::__construct($cache);
            }

            protected function fetch(string $url): string
            {
                return ($this->fetcher)($url);
            }
        };
    }

    public function testTagsFromBookPage(): void
    {
        if (!is_file(self::BOOK_FIXTURE)) {
            self::markTestSkipped('fixture audible-book.html non présente (gitignorée).');
        }

        $scraper = $this->scraper(fn (string $url): string => (string) file_get_contents(self::BOOK_FIXTURE));

        $tags = $scraper->tags('B01M2WSG6T');

        foreach (['Contemporain', 'Fantasy', 'Science-fiction', 'Sorcellerie et épées', 'Épique'] as $tag) {
            self::assertContains($tag, $tags, "le tag « $tag » manque");
        }
    }
    public function testTagsRetries503ErrorPage(): void
    {
        if (!is_file(self::BOOK_FIXTURE)) {
            self::markTestSkipped('fixture audible-book.html non présente (gitignorée).');
        }

        // Audible renvoie parfois sa page d'erreur (503, ~2 Ko) sans faire
        // échouer le transfert : on retente jusqu'à obtenir la fiche.
        $errorPage = '<html><body>Service Unavailable</body></html>';
        $calls = 0;

        $scraper = $this->scraper(function (string $url) use (&$calls, $errorPage): string {
            $calls++;

            return $calls >= 3 ? (string) file_get_contents(self::BOOK_FIXTURE) : $errorPage;
        });

        self::assertContains('Fantasy', $scraper->tags('B01M2WSG6T'));
    }
    public function testTagsThrowsOnSmallErrorPageAfterRetries(): void
    {
        // 503 épuisée (3 tentatives) : la page reçue est une page d'erreur, pas
        // une fiche — jeter (le command distingue l'échec à retraiter d'une fiche
        // réellement sans tags ; le contrôleur frontend attrape et sert 200 + []).
        $errorPage = '<html><body>Service Unavailable</body></html>';

        $scraper = $this->scraper(fn (): string => $errorPage);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('page too small');

        $scraper->tags('B01M2WSG6T');
    }
}
