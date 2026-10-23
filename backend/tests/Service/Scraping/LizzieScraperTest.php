<?php

namespace App\Tests\Service\Scraping;

use App\Service\Scraping\Lizzie\LizzieCache;
use App\Service\Scraping\Lizzie\LizzieItem;
use App\Service\Scraping\Lizzie\LizzieScraper;
use PHPUnit\Framework\TestCase;

class LizzieScraperTest extends TestCase
{
    public function testSearchMatchesByTitle(): void
    {
        $scraper = $this->createScraperWithLibrary([
            $this->makeRaw('harry-potter', 'Harry Potter', 'J.K. Rowling'),
            $this->makeRaw('lotr', 'Le Seigneur des Anneaux', 'J.R.R. Tolkien'),
        ]);

        $results = $scraper->search('harry');

        self::assertCount(1, $results);
        self::assertSame('Harry Potter', $results[0]->getTitle());
    }

    public function testSearchMatchesByAuthor(): void
    {
        $scraper = $this->createScraperWithLibrary([
            $this->makeRaw('book1', 'Titre A', 'Tolkien'),
            $this->makeRaw('book2', 'Titre B', 'Rowling'),
        ]);

        $results = $scraper->search('tolkien');

        self::assertCount(1, $results);
        self::assertSame('Titre A', $results[0]->getTitle());
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $scraper = $this->createScraperWithLibrary([
            $this->makeRaw('b1', 'Harry Potter', 'J.K. Rowling'),
        ]);

        $results = $scraper->search('harry');

        self::assertCount(1, $results);
    }

    public function testSearchMatchesAccentsAndApostrophes(): void
    {
        // Le pattern arrive normalisé (accents/apostrophes retirés) : le titre brut
        // doit être normalisé de la même façon pour matcher.
        $scraper = $this->createScraperWithLibrary([
            $this->makeRaw('b1', "L'Étranger", 'Albert Camus'),
            $this->makeRaw('b2', 'Harry Potter à l\'école des sorciers', 'J.K. Rowling'),
        ]);

        self::assertCount(1, $scraper->search('l etranger'));
        self::assertCount(1, $scraper->search('ecole'));
    }

    public function testSearchMatchesWordsInAnyOrder(): void
    {
        $scraper = $this->createScraperWithLibrary([
            $this->makeRaw('b1', 'Harry Potter à l\'école des sorciers', 'J.K. Rowling'),
        ]);

        // Avant : la phrase entière devait être une sous-chaîne contiguë → raté.
        self::assertCount(1, $scraper->search('sorciers potter harry'));
    }

    public function testSearchRanksBestMatchesFirst(): void
    {
        $scraper = $this->createScraperWithLibrary([
            $this->makeRaw('b1', 'Harry Potter à l\'école des sorciers', 'J.K. Rowling'),
            $this->makeRaw('b2', 'Le bal des Potter et des Harry', 'Autre'),
        ]);

        $results = $scraper->search('harry potter');

        self::assertCount(2, $results);
        // Le titre qui commence par la phrase (90) passe devant tous-les-mots (60).
        self::assertSame('b1', $results[0]->getScrapId());
    }

    public function testSearchMatchesUnderscoredPattern(): void
    {
        // Terme dérivé d'un nom de fichier à underscores (« dune_frank_herbert »).
        $scraper = $this->createScraperWithLibrary([
            $this->makeRaw('b1', 'Dune', 'Frank Herbert'),
        ]);

        self::assertCount(1, $scraper->search('dune_frank_herbert'));
    }

    public function testSearchReturnsMax50Results(): void
    {
        $rawItems = [];
        for ($i = 0; $i < 60; $i++) {
            $rawItems[] = $this->makeRaw("book-$i", "Livre $i matching", 'Auteur');
        }

        $scraper = $this->createScraperWithLibrary($rawItems);

        $results = $scraper->search('matching');

        self::assertCount(50, $results);
    }

    public function testSearchReturnsEmptyArrayOnNoMatch(): void
    {
        $scraper = $this->createScraperWithLibrary([
            $this->makeRaw('b1', 'Harry Potter', 'J.K. Rowling'),
        ]);

        $results = $scraper->search('zebre inconnu');

        self::assertCount(0, $results);
    }

    public function testSearchReturnsEmptyArrayOnEmptyLibrary(): void
    {
        $scraper = $this->createScraperWithLibrary([]);

        $results = $scraper->search('anything');

        self::assertCount(0, $results);
    }

    public function testSearchConvertsResultsToBookEntities(): void
    {
        $scraper = $this->createScraperWithLibrary([
            $this->makeRaw('slug-1', 'Mon Livre', 'Mon Auteur'),
        ]);

        $results = $scraper->search('mon livre');

        self::assertCount(1, $results);
        self::assertInstanceOf(\App\Entity\Book::class, $results[0]);
    }

    public function testGetReturnsNull(): void
    {
        $scraper = $this->createScraperWithLibrary([
            $this->makeRaw('my-slug', 'Title', 'Author'),
        ]);

        $result = $scraper->get('unknown-slug');

        self::assertNull($result);
    }

    public function testGetReturnsBookForMatchingSlug(): void
    {
        $scraper = $this->createScraperWithLibrary([
            $this->makeRaw('my-slug', 'Title', 'Author'),
        ]);

        $result = $scraper->get('my-slug');

        self::assertNotNull($result);
        self::assertSame('my-slug', $result->getScrapId());
        self::assertSame('Title', $result->getTitle());
        self::assertSame('Author', $result->getAuthor());
    }

    private function createScraperWithLibrary(array $rawItems): LizzieScraper
    {
        $cache = $this->createStub(LizzieCache::class);
        $cache->method('getLibrary')->willReturn(
            array_map(fn($raw) => new LizzieItem($raw), $rawItems),
        );

        return new LizzieScraper($cache);
    }

    private function makeRaw(string $slug, string $title, string $author): \stdClass
    {
        $raw = new \stdClass();
        $raw->slug = $slug;
        $raw->title = $title;
        $raw->author = $author;
        $raw->imgSrc = "https://example.com/$slug.jpg";
        $raw->overallDuration = 60;
        $raw->narrator = 'Narrateur';

        return $raw;
    }
}
