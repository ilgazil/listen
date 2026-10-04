<?php

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Service\Scraping\Audible\AudibleScraper;
use App\Service\Scraping\Lizzie\LizzieScraper;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ScrapControllerTest extends WebTestCase
{
    public function testScrapReturnsEmptyArrayWithoutPattern(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/scrap');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame([], json_decode($client->getResponse()->getContent(), true));
    }

    public function testScrapNormalizesPatternAndMergesResults(): void
    {
        $client = static::createClient();

        $lizzieBook = (new Book())
            ->setScrapId('lizzie-1')
            ->setTitle('Harry Potter')
            ->setCover('https://example.com/c.jpg')
            ->setAuthor('J.K. Rowling')
            ->setNarrators(['Un narrateur'])
            ->setSaga('Poudlard');

        $audibleBook = (new Book())
            ->setScrapId('B000123')
            ->setTitle('Le Seigneur des Anneaux')
            ->setCover('https://example.com/c2.jpg')
            ->setAuthor('J.R.R. Tolkien')
            ->setNarrators(['Un narrateur'])
            ->setSaga('Terre du Milieu');

        $lizzie = $this->createMock(LizzieScraper::class);
        $lizzie->expects(self::once())->method('search')->with('harry potter')->willReturn([$lizzieBook]);

        $audible = $this->createMock(AudibleScraper::class);
        $audible->expects(self::once())->method('search')->with('harry potter')->willReturn([$audibleBook]);

        $container = $client->getContainer();
        $container->set(LizzieScraper::class, $lizzie);
        $container->set(AudibleScraper::class, $audible);

        $client->request('GET', '/api/scrap?pattern=Harry%20Potter%20!');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(['lizzie-1', 'B000123'], array_column($data, 'scrap_id'));
    }

    public function testScrapReturnsEmptyArrayWhenBothScrapersReturnEmpty(): void
    {
        $client = static::createClient();

        $lizzie = $this->createStub(LizzieScraper::class);
        $lizzie->method('search')->willReturn([]);

        $audible = $this->createStub(AudibleScraper::class);
        $audible->method('search')->willReturn([]);

        $container = $client->getContainer();
        $container->set(LizzieScraper::class, $lizzie);
        $container->set(AudibleScraper::class, $audible);

        $client->request('GET', '/api/scrap?pattern=nonsense');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame([], json_decode($client->getResponse()->getContent(), true));
    }

    public function testScrapSerializedFieldsMatchBookReadGroup(): void
    {
        $client = static::createClient();

        $book = (new Book())
            ->setScrapId('test-1')
            ->setTitle('Test Book')
            ->setCover('https://example.com/c.jpg')
            ->setAuthor('Author')
            ->setNarrators(['Narrator'])
            ->setSaga('Saga')
            ->setTome('2')
            ->setRuntime('05:30')
            ->setRatings(4.5)
            ->setScraper('audible');

        $lizzie = $this->createStub(LizzieScraper::class);
        $lizzie->method('search')->willReturn([$book]);

        $audible = $this->createStub(AudibleScraper::class);
        $audible->method('search')->willReturn([]);

        $container = $client->getContainer();
        $container->set(LizzieScraper::class, $lizzie);
        $container->set(AudibleScraper::class, $audible);

        $client->request('GET', '/api/scrap?pattern=test');

        $data = json_decode($client->getResponse()->getContent(), true);

        self::assertCount(1, $data);
        $item = $data[0];
        self::assertSame('test-1', $item['scrap_id']);
        self::assertSame('Test Book', $item['title']);
        self::assertSame('Author', $item['author']);
        self::assertSame('Saga', $item['saga']);
        self::assertSame('2', $item['tome']);
        self::assertSame('05:30', $item['runtime']);
        self::assertSame(4.5, $item['ratings']);
        self::assertSame('audible', $item['scraper']);
        self::assertSame(['Narrator'], $item['narrators']);
    }

    public function testScrapCollapsesMultipleSpacesInPattern(): void
    {
        $client = static::createClient();

        $lizzie = $this->createMock(LizzieScraper::class);
        $lizzie->expects(self::once())->method('search')->with('multiple spaces')->willReturn([]);

        $audible = $this->createMock(AudibleScraper::class);
        $audible->expects(self::once())->method('search')->with('multiple spaces')->willReturn([]);

        $container = $client->getContainer();
        $container->set(LizzieScraper::class, $lizzie);
        $container->set(AudibleScraper::class, $audible);

        $client->request('GET', '/api/scrap?pattern=Multiple%20%20%20Spaces');
    }
}
