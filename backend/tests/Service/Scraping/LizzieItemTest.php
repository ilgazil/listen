<?php

namespace App\Tests\Service\Scraping;

use App\Service\Scraping\Lizzie\LizzieItem;
use App\Service\Scraping\Lizzie\LizzieScraper;
use PHPUnit\Framework\TestCase;

class LizzieItemTest extends TestCase
{
    private LizzieItem $item;

    protected function setUp(): void
    {
        $raw = new \stdClass();
        $raw->slug = 'harry-potter-1';
        $raw->title = 'Harry Potter à l\'école des sorciers';
        $raw->author = 'J.K. Rowling';
        $raw->imgSrc = 'https://example.com/cover.jpg';
        $raw->overallDuration = 480;
        $raw->narrator = 'Jean-Claude Drouot';

        $this->item = new LizzieItem($raw);
    }

    public function testGetScrapId(): void
    {
        self::assertSame('harry-potter-1', $this->item->getScrapId());
    }

    public function testGetTitle(): void
    {
        self::assertSame('Harry Potter à l\'école des sorciers', $this->item->getTitle());
    }

    public function testGetAuthor(): void
    {
        self::assertSame('J.K. Rowling', $this->item->getAuthor());
    }

    public function testGetCover(): void
    {
        self::assertSame('https://example.com/cover.jpg', $this->item->getCover());
    }

    public function testGetRuntimeConvertsMinutesToHiFormat(): void
    {
        self::assertSame('08:00', $this->item->getRuntime());
    }

    public function testGetRuntimeZeroMinutes(): void
    {
        $raw = new \stdClass();
        $raw->slug = 'x';
        $raw->title = 'X';
        $raw->author = 'A';
        $raw->imgSrc = 'C';
        $raw->overallDuration = 0;
        $raw->narrator = 'N';

        self::assertSame('00:00', (new LizzieItem($raw))->getRuntime());
    }

    public function testGetNarrators(): void
    {
        self::assertSame('Jean-Claude Drouot', $this->item->getNarrators());
    }

    public function testGetRawReturnsOriginalObject(): void
    {
        $raw = $this->item->getRaw();

        self::assertSame('harry-potter-1', $raw->slug);
    }

    public function testToBookCreatesBookEntity(): void
    {
        $book = $this->item->toBook();

        self::assertSame('Harry Potter à l\'école des sorciers', $book->getTitle());
        self::assertSame('J.K. Rowling', $book->getAuthor());
        self::assertSame('https://example.com/cover.jpg', $book->getCover());
        self::assertSame('08:00', $book->getRuntime());
        self::assertSame(['Jean-Claude Drouot'], $book->getNarrators());
        self::assertSame(LizzieScraper::$NAME, $book->getScraper());
        self::assertSame('harry-potter-1', $book->getScrapId());
    }

    public function testToBookHasNoSagaNorTome(): void
    {
        $book = $this->item->toBook();

        self::assertNull($book->getSaga());
        self::assertNull($book->getTome());
    }

    public function testToBookHasNoRatings(): void
    {
        $book = $this->item->toBook();

        self::assertNull($book->getRatings());
    }
}
