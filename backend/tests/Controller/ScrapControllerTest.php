<?php

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Service\Scraping\AntiBotException;
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

    public function testScrapKeepsResultsWhenAScraperFails(): void
    {
        // Un scraper en échec (réseau, anti-bot…) ne doit pas priver des résultats de l'autre.
        $client = static::createClient();

        $audibleBook = (new Book())
            ->setScrapId('B000123')
            ->setTitle('Harry Potter')
            ->setCover('https://example.com/c.jpg')
            ->setAuthor('J.K. Rowling')
            ->setNarrators(['Un narrateur']);

        $lizzie = $this->createStub(LizzieScraper::class);
        $lizzie->method('search')->willThrowException(new \RuntimeException('Lizzie hors ligne'));

        $audible = $this->createStub(AudibleScraper::class);
        $audible->method('search')->willReturn([$audibleBook]);

        $container = $client->getContainer();
        $container->set(LizzieScraper::class, $lizzie);
        $container->set(AudibleScraper::class, $audible);

        $client->request('GET', '/api/scrap?pattern=harry');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(['B000123'], array_column($data, 'scrap_id'));
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

    public function testScrapUrlReturnsTheBookFromAScraperUrl(): void
    {
        $client = static::createClient();

        $urlBook = (new Book())
            ->setScrapId('B000123')
            ->setTitle('Fiche depuis URL')
            ->setCover('https://example.com/c.jpg')
            ->setAuthor('Auteur')
            ->setNarrators(['Narrateur'])
            ->setScraper('audible');

        $rawUrl = 'https://www.audible.fr/pd/Book/B000123?ascsubtag=xx';

        // L'URL est passée brute au scraper ; la recherche par termes n'est
        // jamais consultée. Audible répond en premier, la boucle s'arrête.
        $audible = $this->createMock(AudibleScraper::class);
        $audible->expects(self::once())->method('fromUrl')->with($rawUrl)->willReturn($urlBook);
        $audible->expects(self::never())->method('search');

        $lizzie = $this->createMock(LizzieScraper::class);
        $lizzie->expects(self::never())->method('fromUrl');
        $lizzie->expects(self::never())->method('search');

        $container = $client->getContainer();
        $container->set(LizzieScraper::class, $lizzie);
        $container->set(AudibleScraper::class, $audible);

        $client->request('GET', '/api/scrap/url?url=' . urlencode($rawUrl));

        self::assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(['B000123'], array_column($data, 'scrap_id'));
    }

    public function testScrapUrlFallsBackToTheNextScraper(): void
    {
        // Audible ne reconnaît pas l'URL (fromUrl → null) : Lizzie est consultée.
        $client = static::createClient();

        $urlBook = (new Book())
            ->setScrapId('lizzie-9')
            ->setTitle('Fiche depuis URL')
            ->setCover('https://example.com/c.jpg')
            ->setAuthor('Auteur')
            ->setNarrators(['Narrateur'])
            ->setScraper('lizzie');

        $rawUrl = 'https://lizzie.audio/content/royal-blood-tome-1';

        $audible = $this->createStub(AudibleScraper::class);
        $audible->method('fromUrl')->willReturn(null);
        $audible->method('search')->willReturn([]);

        $lizzie = $this->createMock(LizzieScraper::class);
        $lizzie->expects(self::once())->method('fromUrl')->with($rawUrl)->willReturn($urlBook);

        $container = $client->getContainer();
        $container->set(LizzieScraper::class, $lizzie);
        $container->set(AudibleScraper::class, $audible);

        $client->request('GET', '/api/scrap/url?url=' . urlencode($rawUrl));

        self::assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(['lizzie-9'], array_column($data, 'scrap_id'));
    }

    public function testScrapUrlReturnsEmptyListWhenNoScraperMatches(): void
    {
        // Hôte inconnu des deux scrapers (fromUrl → null) : liste vide, la
        // requête reste 200 (le formulaire garde la saisie manuelle possible).
        $client = static::createClient();

        $audible = $this->createStub(AudibleScraper::class);
        $audible->method('fromUrl')->willReturn(null);

        $lizzie = $this->createStub(LizzieScraper::class);
        $lizzie->method('fromUrl')->willReturn(null);

        $container = $client->getContainer();
        $container->set(LizzieScraper::class, $lizzie);
        $container->set(AudibleScraper::class, $audible);

        $client->request('GET', '/api/scrap/url?url=' . urlencode('https://exemple.com/page'));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame([], json_decode($client->getResponse()->getContent(), true));
    }

    public function testScrapUrlReturnsEmptyListWhenScrapFails(): void
    {
        // fromUrl jette (réseau…) : l'échec générique est isolé (log), la
        // requête reste 200 avec une liste vide.
        $client = static::createClient();

        $audible = $this->createStub(AudibleScraper::class);
        $audible->method('fromUrl')->willThrowException(new \RuntimeException('Audible hors ligne'));

        $lizzie = $this->createStub(LizzieScraper::class);
        $lizzie->method('fromUrl')->willReturn(null);

        $container = $client->getContainer();
        $container->set(LizzieScraper::class, $lizzie);
        $container->set(AudibleScraper::class, $audible);

        $client->request('GET', '/api/scrap/url?url=' . urlencode('https://www.audible.fr/pd/Book/B000456'));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame([], json_decode($client->getResponse()->getContent(), true));
    }

    public function testScrapUrlReturnsTheAntiBotErrorWhenBlocked(): void
    {
        // Mur anti-bot : l'erreur explicite du scraper part en 502 pour être
        // affichée telle quelle sur le formulaire (réessayable après cooldown).
        $client = static::createClient();

        $message = 'Le scrap de la fiche a été bloqué par l\'anti-bot d\'Audible : réessaie dans quelques minutes.';

        $audible = $this->createStub(AudibleScraper::class);
        $audible->method('fromUrl')->willThrowException(new AntiBotException($message));

        $lizzie = $this->createStub(LizzieScraper::class);
        $lizzie->method('fromUrl')->willReturn(null);

        $container = $client->getContainer();
        $container->set(LizzieScraper::class, $lizzie);
        $container->set(AudibleScraper::class, $audible);

        $client->request('GET', '/api/scrap/url?url=' . urlencode('https://www.audible.fr/pd/Book/B000456'));

        self::assertSame(502, $client->getResponse()->getStatusCode());
        self::assertSame(['error' => $message], json_decode($client->getResponse()->getContent(), true));
    }

    public function testScrapUrlReturnsEmptyListWithoutUrl(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/scrap/url');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame([], json_decode($client->getResponse()->getContent(), true));
    }

    public function testScrapTagsReturnsAudibleBookTags(): void
    {
        $client = static::createClient();

        $audible = $this->createMock(AudibleScraper::class);
        $audible->expects(self::once())->method('tags')->with('B01M2WSG6T')->willReturn(['Fantasy', 'Épique']);

        $container = $client->getContainer();
        $container->set(AudibleScraper::class, $audible);

        $client->request('GET', '/api/scrap/tags?scraper=audible&scrap_id=B01M2WSG6T');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(['tags' => ['Fantasy', 'Épique']], json_decode($client->getResponse()->getContent(), true));
    }

    public function testScrapTagsRejectsUnknownScraper(): void
    {
        $client = static::createClient();

        $audible = $this->createMock(AudibleScraper::class);
        $audible->expects(self::never())->method('tags');

        $container = $client->getContainer();
        $container->set(AudibleScraper::class, $audible);

        $client->request('GET', '/api/scrap/tags?scraper=lizzie&scrap_id=un-slug');

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testScrapTagsRejectsMissingScrapId(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/scrap/tags?scraper=audible');

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testScrapTagsReturnsEmptyListWhenScrapFails(): void
    {
        // Un échec (réseau, anti-bot…) ne doit pas faire échouer la requête :
        // liste vide — le champ reste éditable à la main côté frontend.
        $client = static::createClient();

        $audible = $this->createStub(AudibleScraper::class);
        $audible->method('tags')->willThrowException(new \RuntimeException('Audible hors ligne'));

        $container = $client->getContainer();
        $container->set(AudibleScraper::class, $audible);

        $client->request('GET', '/api/scrap/tags?scraper=audible&scrap_id=B01M2WSG6T');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(['tags' => []], json_decode($client->getResponse()->getContent(), true));
    }
}
