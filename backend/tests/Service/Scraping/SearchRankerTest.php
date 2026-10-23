<?php

namespace App\Tests\Service\Scraping;

use App\Entity\Book;
use App\Service\Scraping\SearchRanker;
use PHPUnit\Framework\TestCase;

class SearchRankerTest extends TestCase
{
    public function testNormalizeStripsAccentsAndPunctuation(): void
    {
        self::assertSame('l etranger', SearchRanker::normalize("L'Étranger"));
        self::assertSame('harry potter', SearchRanker::normalize('  Harry   POTTER ! '));
        self::assertSame('dune frank herbert', SearchRanker::normalize('Dune_Frank_Herbert'));
        self::assertSame('', SearchRanker::normalize('!?'));
    }

    public function testWordsDropsSingleCharWordsUnlessAlone(): void
    {
        self::assertSame(['harry', 'ecole', 'potter'], SearchRanker::words('Harry, à l\'école d H. potter'));
        self::assertSame(['j', 'k'], SearchRanker::words('J.K.'));
    }

    public function testScoreHierarchy(): void
    {
        $pattern = 'harry potter';

        $exact = SearchRanker::score($pattern, 'Harry Potter');
        $startsWith = SearchRanker::score($pattern, 'Harry Potter à l\'école des sorciers');
        $allWordsInTitle = SearchRanker::score($pattern, 'Le bal des Harry et des Potter');
        $allWordsSpread = SearchRanker::score($pattern, 'Harry le Grand', 'Potter & Fils');
        $partial = SearchRanker::score($pattern, 'Potter nomade');
        $none = SearchRanker::score($pattern, 'Le Seigneur des Anneaux', 'Tolkien');

        self::assertSame(100, $exact);
        self::assertSame(90, $startsWith);
        self::assertGreaterThan($allWordsSpread, $allWordsInTitle);
        self::assertGreaterThan($partial, $allWordsSpread);
        self::assertGreaterThan(0, $partial);
        self::assertSame(0, $none);
    }

    public function testMergeRanksDedupsAndCaps(): void
    {
        $pattern = 'dune';

        $lizzie = [
            $this->book('lizzie-1', 'Dune', 'Frank Herbert', ''),
            $this->book('lizzie-2', 'Le Messie de Dune', 'Frank Herbert', 'Dune'),
        ];
        $audible = [
            // Doublon de lizzie-1 (même titre + auteur), sans saga : la version Lizzie garde la main.
            $this->book('B1', 'Dune', 'Frank Herbert', ''),
            $this->book('B2', 'Les enfants de Dune', 'Frank Herbert', 'Dune'),
            $this->book('B3', 'Titre sans rapport', 'Inconnu', ''),
        ];

        $merged = SearchRanker::merge($pattern, $lizzie, $audible);

        // Dédup : 4 livres uniques.
        self::assertCount(4, $merged);
        // « Dune » exact en tête, le hors-sujet (score 0) en fin.
        self::assertSame('lizzie-1', $merged[0]->getScrapId());
        self::assertSame('B3', $merged[3]->getScrapId());
    }

    public function testMergePrefersBookWithSagaOnTie(): void
    {
        $pattern = 'dune';

        $lizzie = [$this->book('lizzie-1', 'Dune', 'Frank Herbert', '')];
        $audible = [$this->book('B1', 'Dune', 'Frank Herbert', 'Dune')];

        $merged = SearchRanker::merge($pattern, $lizzie, $audible);

        self::assertSame('B1', $merged[0]->getScrapId());
    }

    public function testMergeCapsAtMaxResults(): void
    {
        $pattern = 'dune';

        $books = [];
        for ($i = 0; $i < SearchRanker::MAX_RESULTS + 10; $i++) {
            $books[] = $this->book("b-$i", "Dune tome $i", 'Auteur', '');
        }

        self::assertCount(SearchRanker::MAX_RESULTS, SearchRanker::merge($pattern, $books));
    }

    private function book(string $scrapId, string $title, string $author, string $saga): Book
    {
        return (new Book())
            ->setScrapId($scrapId)
            ->setTitle($title)
            ->setAuthor($author)
            ->setSaga($saga)
        ;
    }
}
