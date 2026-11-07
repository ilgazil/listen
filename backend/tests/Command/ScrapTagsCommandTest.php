<?php

namespace App\Tests\Command;

use App\Command\ScrapTagsCommand;
use App\Entity\Book;
use App\Repository\BookRepository;
use App\Service\Scraping\Audible\AudibleScraper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ScrapTagsCommandTest extends TestCase
{
    public function testUpdatesTagsAndFlushes(): void
    {
        $book = $this->createBook('B00001', 'Ancien tag');
        $scraper = $this->createStub(AudibleScraper::class);
        $scraper->method('tags')->willReturn(['Fantasy', 'Épique']);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $tester = $this->runCommand($entityManager, [$book], $scraper);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['Fantasy', 'Épique'], $book->getTags());
        self::assertStringContainsString('[update] Fantôme — Fantasy, Épique (was: Ancien tag)', $tester->getDisplay());
    }

    public function testDryRunListsChangesWithoutSaving(): void
    {
        $book = $this->createBook('B00001', 'Ancien tag');
        $scraper = $this->createStub(AudibleScraper::class);
        $scraper->method('tags')->willReturn(['Fantasy']);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $tester = $this->runCommand($entityManager, [$book], $scraper, ['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('(not saved)', $tester->getDisplay());
    }

    public function testNeverOverwritesTagsWithEmptyFetch(): void
    {
        $book = $this->createBook('B00001', 'Fantasy');
        $scraper = $this->createStub(AudibleScraper::class);
        $scraper->method('tags')->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $tester = $this->runCommand($entityManager, [$book], $scraper);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['Fantasy'], $book->getTags());
        self::assertStringContainsString('(kept: Fantasy)', $tester->getDisplay());
    }

    public function testSkipsBooksWithoutScrapId(): void
    {
        $book = $this->createBook('', 'Fantasy');
        $scraper = $this->createMock(AudibleScraper::class);
        $scraper->expects($this->never())->method('tags');

        $entityManager = $this->createStub(EntityManagerInterface::class);

        $tester = $this->runCommand($entityManager, [$book], $scraper);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('no scraper id', $tester->getDisplay());
    }

    public function testOnlyFetchesAudibleBooks(): void
    {
        // createMock (et non createStub) : la vérification `with()` du critère
        // findBy exige le full mocker (PHPUnit 13).
        $repository = $this->createMock(BookRepository::class);
        $repository->expects($this->once())->method('findBy')
            ->with($this->identicalTo(['scraper' => 'audible']))
            ->willReturn([]);

        $command = $this->createCommand($this->createStub(EntityManagerInterface::class), $repository, $this->createStub(AudibleScraper::class));
        $command->setName('app:scrap-tags');

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No book to process.', $tester->getDisplay());
    }

    public function testScrapIdOptionTargetsSingleBook(): void
    {
        $repository = $this->createMock(BookRepository::class);
        $repository->expects($this->once())->method('findBy')
            ->with($this->identicalTo(['scraper' => 'audible', 'scrap_id' => 'B00042']))
            ->willReturn([]);

        $command = $this->createCommand($this->createStub(EntityManagerInterface::class), $repository, $this->createStub(AudibleScraper::class));
        $command->setName('app:scrap-tags');

        $tester = new CommandTester($command);
        $tester->execute(['--scrap-id' => 'B00042']);

        self::assertSame(0, $tester->getStatusCode());
    }

    public function testLimitStopsFetchingAfterNPages(): void
    {
        $first = $this->createBook('B00001', 'Ancien');
        $second = $this->createBook('B00002', 'Ancien');
        $third = $this->createBook('B00003', 'Ancien');

        $scraper = $this->createMock(AudibleScraper::class);
        $scraper->expects($this->exactly(2))->method('tags')->willReturn(['Fantasy']);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->exactly(2))->method('flush');

        $tester = $this->runCommand($entityManager, [$first, $second, $third], $scraper, ['--limit' => '2']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['Fantasy'], $first->getTags());
        self::assertSame(['Fantasy'], $second->getTags());
        // Le troisième n'a pas été fetché : ses tags d'origine sont conservés.
        self::assertSame(['Ancien'], $third->getTags());
    }

    public function testScrapingFailureKeepsTagsAndContinues(): void
    {
        $broken = $this->createBook('B00001', 'Fantasy');
        $healthy = $this->createBook('B00002', 'Ancien');

        $scraper = $this->createMock(AudibleScraper::class);
        $scraper->expects($this->exactly(2))->method('tags')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RuntimeException('503 wall')),
                ['Aventure'],
            );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $tester = $this->runCommand($entityManager, [$broken, $healthy], $scraper);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['Fantasy'], $broken->getTags());
        self::assertSame(['Aventure'], $healthy->getTags());
        self::assertStringContainsString('[error] Fantôme — 503 wall', $tester->getDisplay());
    }

    public function testTagOrderIsNotMeaningful(): void
    {
        $book = $this->createBook('B00001', 'Fantasy, Épique');
        $scraper = $this->createStub(AudibleScraper::class);
        $scraper->method('tags')->willReturn(['Épique', 'Fantasy']);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $tester = $this->runCommand($entityManager, [$book], $scraper);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('up to date', $tester->getDisplay());
    }

    public function testEmptyOnlySkipsAlreadyTaggedBooks(): void
    {
        // Le livre taggé ne doit être ni fetché ni flushé : une relance --empty-only
        // ne fetch que les manques (l'échec de mur de la passe précédente, ici).
        $tagged = $this->createBook('B00001', 'Fantasy');
        $empty = $this->untaggedBook('B00002');

        $scraper = $this->createMock(AudibleScraper::class);
        $scraper->expects($this->once())->method('tags')->willReturn(['Aventure']);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $tester = $this->runCommand($entityManager, [$tagged, $empty], $scraper, ['--empty-only' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['Fantasy'], $tagged->getTags());
        self::assertSame(['Aventure'], $empty->getTags());
        self::assertStringContainsString('1 book(s) already tagged, not fetched', $tester->getDisplay());
    }

    public function testEmptyOnlyWithAllBooksTaggedReportsNothingToFetch(): void
    {
        $tagged = $this->createBook('B00001', 'Fantasy');

        $scraper = $this->createMock(AudibleScraper::class);
        $scraper->expects($this->never())->method('tags');

        $tester = $this->runCommand($this->createStub(EntityManagerInterface::class), [$tagged], $scraper, ['--empty-only' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('All 1 book(s) already tagged, nothing to fetch', $tester->getDisplay());
    }

    /**
     * Fabrique la commande avec une pause anti rate-limiting neutralisée :
     * la suite ne doit jamais dormir 0,5 s entre deux fetches.
     */
    private function createCommand(
        EntityManagerInterface $entityManager,
        BookRepository $repository,
        AudibleScraper $scraper,
    ): ScrapTagsCommand {
        return new class($entityManager, $repository, $scraper) extends ScrapTagsCommand {
            protected function pause(): void
            {
            }
        };
    }

    private function runCommand(
        EntityManagerInterface $entityManager,
        array $books,
        AudibleScraper $scraper,
        array $options = [],
    ): CommandTester {
        $repository = $this->createStub(BookRepository::class);
        $repository->method('findBy')->willReturn($books);

        $command = $this->createCommand($entityManager, $repository, $scraper);
        $command->setName('app:scrap-tags');

        $tester = new CommandTester($command);
        $tester->execute($options);

        return $tester;
    }

    private function createBook(string $scrapId, string $tags): Book
    {
        $book = new Book();
        $book->setScraper('audible');
        $book->setScrapId($scrapId);
        $book->setTitle('Fantôme');
        $book->setTags($scrapId === '' ? [] : [$tags]);

        return $book;
    }

    /**
     * Livre avec ASIN mais sans tags : l'état d'un échec de mur après une passe,
     * la cible de --empty-only.
     */
    private function untaggedBook(string $scrapId): Book
    {
        $book = new Book();
        $book->setScraper('audible');
        $book->setScrapId($scrapId);
        $book->setTitle('Fantôme');

        return $book;
    }
}
