<?php

namespace App\Tests\Service\Scraping;

use App\Entity\Book;
use App\Service\Scraping\Audible\AudibleScraper;
use App\Service\Scraping\Lizzie\LizzieScraper;
use App\Service\Scraping\ScrapperFactory;
use PHPUnit\Framework\TestCase;

class ScrapperFactoryTest extends TestCase
{
    private ScrapperFactory $factory;
    private AudibleScraper $audibleScraper;
    private LizzieScraper $lizzieScraper;

    protected function setUp(): void
    {
        $this->audibleScraper = $this->createStub(AudibleScraper::class);
        $this->lizzieScraper = $this->createStub(LizzieScraper::class);
        $this->factory = new ScrapperFactory($this->audibleScraper, $this->lizzieScraper);
    }

    public function testFromBookReturnsAudibleScraper(): void
    {
        $book = (new Book())->setScraper('audible');

        self::assertSame($this->audibleScraper, $this->factory->fromBook($book));
    }

    public function testFromBookReturnsLizzieScraper(): void
    {
        $book = (new Book())->setScraper('lizzie');

        self::assertSame($this->lizzieScraper, $this->factory->fromBook($book));
    }

    public function testFromBookThrowsOnUnknownScraper(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No scrapper matches name: unknown');

        $book = (new Book())->setScraper('unknown');

        $this->factory->fromBook($book);
    }

    public function testFromBookThrowsOnNullScraper(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $book = new Book();

        $this->factory->fromBook($book);
    }
}
