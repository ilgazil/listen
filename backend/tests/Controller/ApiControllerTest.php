<?php

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Service\Api\UnFichierApi;
use App\Service\Notification\Discord\DiscordWebhook;
use App\Service\Notification\Notifier;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ApiControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // La BDD `:memory:` (sqlite) vit sur la connexion du kernel :
        // sans désactivation du reboot, le 2e requête d'un test recrée un
        // kernel neuf (et une connexion vide) → « no such table: book ».
        $this->client->disableReboot();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testUploadWithoutFileReturns400(): void
    {
        $this->client->request('POST', '/api/upload', ['title' => 'Un livre']);

        $response = $this->client->getResponse();

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('error', $response->getContent());
    }

    public function testUploadWithInvalidMimeReturns400(): void
    {
        $txt = $this->tempFile('book.txt');
        file_put_contents($txt, 'not a zip');

        $this->client->request(
            'POST',
            '/api/upload',
            ['title' => 'Un livre'],
            ['file' => new UploadedFile($txt, 'book.txt', 'text/plain', null, true)],
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testUploadWithoutZipExtensionReturns400(): void
    {
        $rar = $this->tempFile('book.rar');
        $this->writeZip($rar);

        $this->client->request(
            'POST',
            '/api/upload',
            ['title' => 'Un livre'],
            ['file' => new UploadedFile($rar, 'book.rar', 'application/x-rar-compressed', null, true)],
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testUploadWithoutTitleReturns400(): void
    {
        $zip = $this->tempFile('book.zip');
        $this->writeZip($zip);

        $this->client->request(
            'POST',
            '/api/upload',
            [],
            ['file' => new UploadedFile($zip, 'book.zip', 'application/zip', null, true)],
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testUploadSuccessReturns201(): void
    {
        $unFichier = $this->createMock(UnFichierApi::class);
        $unFichier->expects(self::once())->method('upload')->willReturn('1fichier123');

        $notifier = $this->createStub(Notifier::class);

        $container = $this->client->getContainer();
        $container->set(UnFichierApi::class, $unFichier);
        $container->set(DiscordWebhook::class, $notifier);

        $zip = $this->tempFile('book.zip');
        $this->writeZip($zip);

        $this->client->request(
            'POST',
            '/api/upload',
            [
                'title' => 'Le Seigneur des Anneaux',
                'author' => 'J.R.R. Tolkien',
                'cover' => 'https://example.com/c.jpg',
                'saga' => 'Terre du Milieu',
                'tome' => '1.5',
                'narrators' => 'Julien Rochefort, Marie Duplex',
                'runtime' => '11:22:33',
                'ratings' => '4.6',
                'scraper' => 'audible',
                'scrap_id' => 'B000123',
            ],
            ['file' => new UploadedFile($zip, 'book.zip', 'application/zip', null, true)],
        );

        $response = $this->client->getResponse();

        self::assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        self::assertSame('1fichier123', $data['id']);
        self::assertSame(['Julien Rochefort', 'Marie Duplex'], $data['narrators']);
        self::assertSame(4.6, $data['ratings']);
        self::assertSame('1.5', $data['tome']);
        self::assertSame('Terre du Milieu', $data['saga']);
    }

    public function testDownloadUnknownBookReturns404(): void
    {
        $this->client->request('GET', '/api/download/unknown');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDownloadKnownBookRedirectsToProviderUrl(): void
    {
        $this->persistBook('1fichier123');

        $unFichier = $this->createMock(UnFichierApi::class);
        $unFichier->method('download')->with('1fichier123')->willReturn('https://example.com/download');
        $this->client->getContainer()->set(UnFichierApi::class, $unFichier);

        $this->client->request('GET', '/api/download/1fichier123');

        $response = $this->client->getResponse();
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('https://example.com/download', $response->headers->get('Location'));
    }

    public function testDownloadWithoutProviderUrlReturns502(): void
    {
        $this->persistBook('1fichier123');

        $unFichier = $this->createStub(UnFichierApi::class);
        $unFichier->method('download')->willReturn('');
        $this->client->getContainer()->set(UnFichierApi::class, $unFichier);

        $this->client->request('GET', '/api/download/1fichier123');

        self::assertSame(502, $this->client->getResponse()->getStatusCode());
    }

    public function testBooksCollectionReturnsEtagAndCacheHeaders(): void
    {
        $this->persistBook('etag-book');

        $this->client->request('GET', '/api/books?pagination=false');

        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($response->headers->get('ETag'));
        self::assertStringContainsString('must-revalidate', (string) $response->headers->get('Cache-Control'));
    }

    public function testBooksCollectionReturns304WhenEtagMatches(): void
    {
        $this->persistBook('etag-304');

        $this->client->request('GET', '/api/books?pagination=false');

        $etag = $this->client->getResponse()->headers->get('ETag');
        self::assertNotNull($etag);

        $this->client->request(
            'GET',
            '/api/books?pagination=false',
            [],
            [],
            ['HTTP_IF_NONE_MATCH' => $etag],
        );

        $response = $this->client->getResponse();

        self::assertSame(304, $response->getStatusCode());
    }

    public function testBooksCollectionReturns200WhenDataChanged(): void
    {
        $this->persistBook('etag-before');

        $this->client->request('GET', '/api/books?pagination=false');

        $oldEtag = $this->client->getResponse()->headers->get('ETag');
        self::assertNotNull($oldEtag);

        $this->persistBook('etag-after');

        $this->client->request(
            'GET',
            '/api/books?pagination=false',
            [],
            [],
            ['HTTP_IF_NONE_MATCH' => $oldEtag],
        );

        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame($oldEtag, $response->headers->get('ETag'));
    }

    public function testBooksCollection304IsEmptyBody(): void
    {
        $this->persistBook('etag-empty');

        $this->client->request('GET', '/api/books?pagination=false');

        $etag = $this->client->getResponse()->headers->get('ETag');

        $this->client->request(
            'GET',
            '/api/books?pagination=false',
            [],
            [],
            ['HTTP_IF_NONE_MATCH' => $etag],
        );

        self::assertSame('', $this->client->getResponse()->getContent());
    }

    public function testUpload1FichierFailureReturns502(): void
    {
        $unFichier = $this->createStub(UnFichierApi::class);
        $unFichier->method('upload')->willThrowException(new \RuntimeException('Connection refused'));

        $notifier = $this->createMock(Notifier::class);
        $notifier->expects(self::never())->method('available');

        $container = $this->client->getContainer();
        $container->set(UnFichierApi::class, $unFichier);
        $container->set(DiscordWebhook::class, $notifier);

        $zip = $this->tempFile('book.zip');
        $this->writeZip($zip);

        $this->client->request(
            'POST',
            '/api/upload',
            ['title' => 'Un livre', 'author' => 'Auteur'],
            ['file' => new UploadedFile($zip, 'book.zip', 'application/zip', null, true)],
        );

        self::assertSame(502, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('1Fichier', $this->client->getResponse()->getContent());
    }

    public function testUploadNotifiesDiscordOnSuccess(): void
    {
        $unFichier = $this->createStub(UnFichierApi::class);
        $unFichier->method('upload')->willReturn('id-notify');

        $notifier = $this->createMock(Notifier::class);
        $notifier->expects(self::once())->method('available')->with(self::callback(function ($book) {
            return $book->getId() === 'id-notify' && $book->getTitle() === 'Notif Test';
        }));

        $container = $this->client->getContainer();
        $container->set(UnFichierApi::class, $unFichier);
        $container->set(DiscordWebhook::class, $notifier);

        $zip = $this->tempFile('book.zip');
        $this->writeZip($zip);

        $this->client->request(
            'POST',
            '/api/upload',
            ['title' => 'Notif Test', 'author' => 'Auteur'],
            ['file' => new UploadedFile($zip, 'book.zip', 'application/zip', null, true)],
        );

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
    }

    public function testUploadWithEmptyNarratorsAndRatingsSucceeds(): void
    {
        $unFichier = $this->createStub(UnFichierApi::class);
        $unFichier->method('upload')->willReturn('id-minimal');

        $notifier = $this->createStub(Notifier::class);

        $container = $this->client->getContainer();
        $container->set(UnFichierApi::class, $unFichier);
        $container->set(DiscordWebhook::class, $notifier);

        $zip = $this->tempFile('book.zip');
        $this->writeZip($zip);

        $this->client->request(
            'POST',
            '/api/upload',
            [
                'title' => 'Minimal Book',
                'author' => 'Author',
                'cover' => 'https://example.com/c.jpg',
                'saga' => '',
                'tome' => '',
                'narrators' => '',
                'runtime' => '',
                'ratings' => '',
                'scraper' => '',
                'scrap_id' => '',
            ],
            ['file' => new UploadedFile($zip, 'book.zip', 'application/zip', null, true)],
        );

        $response = $this->client->getResponse();
        self::assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        self::assertSame([], $data['narrators']);
        self::assertArrayNotHasKey('ratings', $data);
    }

    public function testDownloadBookWhenProviderReturnsEmptyUrlReturns502(): void
    {
        $this->persistBook('empty-url-id');

        $unFichier = $this->createMock(UnFichierApi::class);
        $unFichier->method('download')->with('empty-url-id')->willReturn('');
        $this->client->getContainer()->set(UnFichierApi::class, $unFichier);

        $this->client->request('GET', '/api/download/empty-url-id');

        self::assertSame(502, $this->client->getResponse()->getStatusCode());
    }

    public function testUpdateUnknownBookReturns404(): void
    {
        $this->client->request('PUT', '/api/books/unknown', [], [], [], json_encode(['title' => 'Nouveau titre']));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testUpdateWithInvalidJsonReturns400(): void
    {
        $this->persistBook('valid-id');

        $this->client->request('PUT', '/api/books/valid-id', [], [], [], 'not json');

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testUpdateMetadataDoesNotRenameWhenFilenameUnchanged(): void
    {
        $this->persistBook('rename-same');

        $unFichier = $this->createMock(UnFichierApi::class);
        $unFichier->expects(self::never())->method('rename');
        $this->client->getContainer()->set(UnFichierApi::class, $unFichier);

        $this->client->request(
            'PUT',
            '/api/books/rename-same',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ratings' => 4.9, 'runtime' => '11:00:00']),
        );

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        self::assertSame(4.9, $data['ratings']);
        self::assertSame('11:00:00', $data['runtime']);
    }

    public function testUpdateRenamesFileWhenFilenameChanges(): void
    {
        $this->persistBook('rename-change');

        $unFichier = $this->createMock(UnFichierApi::class);
        $unFichier->expects(self::once())
            ->method('rename')
            ->with('rename-change', 'un-auteur-une-saga-1-nouveau-titre.zip');
        $this->client->getContainer()->set(UnFichierApi::class, $unFichier);

        $this->client->request(
            'PUT',
            '/api/books/rename-change',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'title' => 'Nouveau Titre',
                'author' => 'Un Auteur',
                'saga' => 'Une Saga',
                'tome' => '1',
            ]),
        );

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        self::assertSame('Nouveau Titre', $data['title']);
        self::assertSame('Un Auteur', $data['author']);
        self::assertSame('Une Saga', $data['saga']);
        self::assertSame('1', $data['tome']);
    }

    public function testUpdateRenameFailureReturns502(): void
    {
        $this->persistBook('rename-fail');

        $unFichier = $this->createStub(UnFichierApi::class);
        $unFichier->method('rename')->willThrowException(new \RuntimeException('API error'));
        $this->client->getContainer()->set(UnFichierApi::class, $unFichier);

        $this->client->request(
            'PUT',
            '/api/books/rename-fail',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['title' => 'Autre Titre']),
        );

        self::assertSame(502, $this->client->getResponse()->getStatusCode());
    }

    public function testUpdateRejectsInvalidNarrators(): void
    {
        $this->persistBook('bad-narrators');

        $this->client->request(
            'PUT',
            '/api/books/bad-narrators',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['narrators' => 'not-an-array']),
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testUpdateScrapIdAndScraper(): void
    {
        $this->persistBook('upd-scrap');

        $this->client->request(
            'PUT',
            '/api/books/upd-scrap',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['scraper' => 'lizzie', 'scrap_id' => 'un-slug-lizzie']),
        );

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        self::assertSame('lizzie', $data['scraper']);
        self::assertSame('un-slug-lizzie', $data['scrap_id']);
    }

    public function testOrphansReturnsEmptyListWhenNoKnownIds(): void
    {
        $unFichier = $this->createMock(UnFichierApi::class);
        $unFichier->expects(self::once())
            ->method('getOrphans')
            ->with([])
            ->willReturn([]);
        $this->client->getContainer()->set(UnFichierApi::class, $unFichier);

        $this->client->request('GET', '/api/orphans');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame([], json_decode($this->client->getResponse()->getContent(), true));
    }

    public function testOrphansExcludesKnownBooks(): void
    {
        $this->persistBook('known-id');

        $unFichier = $this->createMock(UnFichierApi::class);
        $unFichier->expects(self::once())
            ->method('getOrphans')
            ->with(['known-id'])
            ->willReturn([
                [
                    'id' => 'orphan-a',
                    'name' => 'orphelin-a.zip',
                    'size' => 1024,
                    'date' => 1000,
                ],
            ]);
        $this->client->getContainer()->set(UnFichierApi::class, $unFichier);

        $this->client->request('GET', '/api/orphans');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame('orphan-a', $data[0]['id']);
    }

    public function testOrphansFailureReturns502(): void
    {
        $unFichier = $this->createStub(UnFichierApi::class);
        $unFichier->method('getOrphans')->willThrowException(new \RuntimeException('Provider error'));
        $this->client->getContainer()->set(UnFichierApi::class, $unFichier);

        $this->client->request('GET', '/api/orphans');

        self::assertSame(502, $this->client->getResponse()->getStatusCode());
    }

    public function testFixOrphanAlreadyRegisteredReturns409(): void
    {
        $this->persistBook('orphan-dup');

        $this->client->request(
            'POST',
            '/api/orphans/orphan-dup',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['title' => 'Doublon']),
        );

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    public function testFixOrphanUnknownFileReturns404(): void
    {
        $unFichier = $this->createStub(UnFichierApi::class);
        $unFichier->method('getOrphans')->willReturn([]);

        $notifier = $this->createMock(Notifier::class);
        $notifier->expects(self::never())->method('available');

        $this->client->getContainer()->set(UnFichierApi::class, $unFichier);
        $this->client->getContainer()->set(DiscordWebhook::class, $notifier);

        $this->client->request(
            'POST',
            '/api/orphans/unknown-hash',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['title' => 'Un livre']),
        );

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testFixOrphanCreatesBookWithoutRename(): void
    {
        $unFichier = $this->createMock(UnFichierApi::class);
        $unFichier->method('getOrphans')
            ->willReturn([
                [
                    'id' => 'orphan-new',
                    'name' => 'orphelin.zip',
                    'size' => 1024,
                    'date' => 1000,
                ],
            ]);
        $unFichier->expects(self::never())->method('rename');
        $unFichier->expects(self::never())->method('remove');

        $notifier = $this->createMock(Notifier::class);
        $notifier->expects(self::once())
            ->method('available')
            ->with(self::callback(function (Book $book) {
                return $book->getId() === 'orphan-new' && $book->getTitle() === 'Livre Orphelin';
            }));

        $this->client->getContainer()->set(UnFichierApi::class, $unFichier);
        $this->client->getContainer()->set(DiscordWebhook::class, $notifier);

        $this->client->request(
            'POST',
            '/api/orphans/orphan-new',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'title' => 'Livre Orphelin',
                'author' => 'Un Auteur',
                'cover' => 'https://example.com/c.jpg',
                'saga' => 'Une Saga',
                'tome' => '1.5',
                'narrators' => ['Narrateur Un', 'Narrateur Deux'],
                'runtime' => '10:00:00',
                'ratings' => 4.6,
                'scraper' => 'audible',
                'scrap_id' => 'B000987',
            ]),
        );

        $response = $this->client->getResponse();
        self::assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        self::assertSame('orphan-new', $data['id']);
        self::assertSame('Livre Orphelin', $data['title']);
        self::assertSame('B000987', $data['scrap_id']);
        self::assertSame(['Narrateur Un', 'Narrateur Deux'], $data['narrators']);
        self::assertSame(4.6, $data['ratings']);
        self::assertSame('Une Saga', $data['saga']);
        self::assertSame('1.5', $data['tome']);

        $stored = static::getContainer()->get(EntityManagerInterface::class)->find(Book::class, 'orphan-new');
        self::assertNotNull($stored);
    }

    public function testFixOrphanWithoutTitleReturns400(): void
    {
        $unFichier = $this->createMock(UnFichierApi::class);
        $unFichier->method('getOrphans')->willReturn([
            ['id' => 'orphan-no-title', 'name' => 'orphelin.zip', 'size' => 1024, 'date' => 1000],
        ]);
        $unFichier->expects(self::never())->method('remove');

        $notifier = $this->createMock(Notifier::class);
        $notifier->expects(self::never())->method('available');

        $this->client->getContainer()->set(UnFichierApi::class, $unFichier);
        $this->client->getContainer()->set(DiscordWebhook::class, $notifier);

        $this->client->request(
            'POST',
            '/api/orphans/orphan-no-title',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['author' => 'Auteur seul']),
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testDeleteOrphanRemovesFileWithoutAffectingKnownBooks(): void
    {
        $this->persistBook('keep-me');

        $unFichier = $this->createMock(UnFichierApi::class);
        $unFichier->expects(self::once())
            ->method('getOrphans')
            ->with(['keep-me'])
            ->willReturn([
                ['id' => 'orphan-del', 'name' => 'orphelin.zip', 'size' => 1024, 'date' => 1000],
            ]);
        $unFichier->expects(self::once())->method('remove')->with('orphan-del');
        $this->client->getContainer()->set(UnFichierApi::class, $unFichier);

        $this->client->request('DELETE', '/api/orphans/orphan-del');

        self::assertSame(204, $this->client->getResponse()->getStatusCode());

        $kept = static::getContainer()->get(EntityManagerInterface::class)->find(Book::class, 'keep-me');
        self::assertNotNull($kept);
    }

    public function testDeleteNonOrphanFileReturns404(): void
    {
        $this->persistBook('keep-me');

        $unFichier = $this->createMock(UnFichierApi::class);
        $unFichier->method('getOrphans')->with(['keep-me'])->willReturn([]);
        $unFichier->expects(self::never())->method('remove');
        $this->client->getContainer()->set(UnFichierApi::class, $unFichier);

        $this->client->request('DELETE', '/api/orphans/keep-me');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDeleteOrphanProviderFailureReturns502(): void
    {
        $unFichier = $this->createStub(UnFichierApi::class);
        $unFichier->method('getOrphans')->willReturn([
            ['id' => 'orphan-fail', 'name' => 'orphelin.zip', 'size' => 1024, 'date' => 1000],
        ]);
        $unFichier->method('remove')->willThrowException(new \RuntimeException('Provider error'));
        $this->client->getContainer()->set(UnFichierApi::class, $unFichier);

        $this->client->request('DELETE', '/api/orphans/orphan-fail');

        self::assertSame(502, $this->client->getResponse()->getStatusCode());
    }

    public function testIntrudersReturnsEmptyListWhenNoStoredFiles(): void
    {
        $this->persistBook('book-only');

        $unFichier = $this->createStub(UnFichierApi::class);
        $unFichier->method('getStoredIds')->willReturn([]);
        $this->client->getContainer()->set(UnFichierApi::class, $unFichier);

        $this->client->request('GET', '/api/intruders');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, json_decode($response->getContent(), true));
    }

    public function testIntrudersListsOnlyBooksWithoutStoredFile(): void
    {
        $this->persistBook('present-id');
        $this->persistBook('ghost-id');

        $unFichier = $this->createMock(UnFichierApi::class);
        $unFichier->expects(self::once())->method('getStoredIds')->willReturn(['present-id']);
        $this->client->getContainer()->set(UnFichierApi::class, $unFichier);

        $this->client->request('GET', '/api/intruders');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame('ghost-id', $data[0]['id']);
    }

    public function testIntrudersFailureReturns502(): void
    {
        $unFichier = $this->createStub(UnFichierApi::class);
        $unFichier->method('getStoredIds')->willThrowException(new \RuntimeException('Provider error'));
        $this->client->getContainer()->set(UnFichierApi::class, $unFichier);

        $this->client->request('GET', '/api/intruders');

        self::assertSame(502, $this->client->getResponse()->getStatusCode());
    }

    public function testDeleteIntruderRemovesBook(): void
    {
        $this->persistBook('ghost-del');

        $unFichier = $this->createStub(UnFichierApi::class);
        $unFichier->method('getStoredIds')->willReturn(['other-id']);
        $this->client->getContainer()->set(UnFichierApi::class, $unFichier);

        $this->client->request('DELETE', '/api/intruders/ghost-del');

        self::assertSame(204, $this->client->getResponse()->getStatusCode());

        $removed = static::getContainer()->get(EntityManagerInterface::class)->find(Book::class, 'ghost-del');
        self::assertNull($removed);
    }

    public function testDeleteIntruderUnknownBookReturns404(): void
    {
        $this->client->request('DELETE', '/api/intruders/unknown');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDeleteIntruderWhenFileStillPresentReturns409(): void
    {
        $this->persistBook('still-there');

        $unFichier = $this->createStub(UnFichierApi::class);
        $unFichier->method('getStoredIds')->willReturn(['still-there']);
        $this->client->getContainer()->set(UnFichierApi::class, $unFichier);

        $this->client->request('DELETE', '/api/intruders/still-there');

        $response = $this->client->getResponse();
        self::assertSame(409, $response->getStatusCode());

        $kept = static::getContainer()->get(EntityManagerInterface::class)->find(Book::class, 'still-there');
        self::assertNotNull($kept);
    }

    public function testDeleteIntruderListingFailureReturns502(): void
    {
        $this->persistBook('ghost-fail');

        $unFichier = $this->createStub(UnFichierApi::class);
        $unFichier->method('getStoredIds')->willThrowException(new \RuntimeException('Provider error'));
        $this->client->getContainer()->set(UnFichierApi::class, $unFichier);

        $this->client->request('DELETE', '/api/intruders/ghost-fail');

        self::assertSame(502, $this->client->getResponse()->getStatusCode());
    }

    public function testReportUploadCutNotifiesAndReturns204(): void
    {
        $notifier = $this->createMock(Notifier::class);
        $notifier->expects(self::once())
            ->method('uploadCut')
            ->with(self::callback(function (Book $book) {
                return $book->getTitle() === 'Livre Coupé' && $book->getSaga() === 'Une saga';
            }));
        $this->client->getContainer()->set(DiscordWebhook::class, $notifier);

        $this->client->request(
            'POST',
            '/api/report-upload-cut',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'title' => 'Livre Coupé',
                'saga' => 'Une saga',
            ]),
        );

        self::assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    public function testReportUploadCutWithoutTitleReturns400(): void
    {
        $notifier = $this->createMock(Notifier::class);
        $notifier->expects(self::never())->method('uploadCut');
        $this->client->getContainer()->set(DiscordWebhook::class, $notifier);

        $this->client->request(
            'POST',
            '/api/report-upload-cut',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['author' => 'Un auteur']),
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testReportUploadCutInvalidJsonReturns400(): void
    {
        $this->client->request(
            'POST',
            '/api/report-upload-cut',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            'not json',
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    private function persistBook(string $id): void
    {
        $book = (new Book())
            ->setId($id)
            ->setTitle('Un livre')
            ->setCover('https://example.com/c.jpg')
            ->setAuthor('Un auteur')
            ->setNarrators(['Un narrateur'])
            ->setSaga('Une saga');

        static::getContainer()->get(EntityManagerInterface::class)->persist($book);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function tempFile(string $name): string
    {
        $path = sys_get_temp_dir() . '/' . uniqid('test-' . $name . '-', true);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function writeZip(string $path): void
    {
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('book.txt', 'audio book content');
        $zip->close();
    }
}
