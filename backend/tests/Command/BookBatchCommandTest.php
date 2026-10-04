<?php

namespace App\Tests\Command;

use App\Command\BookBatchCommand;
use App\Entity\Book;
use App\Service\Scraping\ScrapperFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class BookBatchCommandTest extends TestCase
{
    private \ReflectionMethod $rewriteTome;

    protected function setUp(): void
    {
        $command = new BookBatchCommand(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(ScrapperFactory::class),
        );
        $command->setName('app:batch');

        $this->rewriteTome = (new \ReflectionClass($command))
            ->getMethod('rewriteTome');
    }

    public function testRewriteTomeNormalizesWithPrefix(): void
    {
        $book = new Book();
        $book->setTome('Tome 3');

        $this->rewriteTome->invoke($this->createCommand(), $book);

        self::assertSame('3', $book->getTome());
    }

    public function testRewriteTomeNormalizesVolumePrefix(): void
    {
        $book = new Book();
        $book->setTome('Volume 12');

        $this->rewriteTome->invoke($this->createCommand(), $book);

        self::assertSame('12', $book->getTome());
    }

    public function testRewriteTomeKeepsAlreadyCleanValue(): void
    {
        $book = new Book();
        $book->setTome('5');

        $this->rewriteTome->invoke($this->createCommand(), $book);

        self::assertSame('5', $book->getTome());
    }

    public function testRewriteTomeHandlesDecimalValue(): void
    {
        $book = new Book();
        $book->setTome('2.5');

        $this->rewriteTome->invoke($this->createCommand(), $book);

        self::assertSame('2.5', $book->getTome());
    }

    public function testRewriteTomeHandlesNullValue(): void
    {
        $book = new Book();

        $this->rewriteTome->invoke($this->createCommand(), $book);

        self::assertNull($book->getTome());
    }

    public function testRewriteTomeStripsNonNumericCharacters(): void
    {
        $book = new Book();
        $book->setTome('Tome 4 bis');

        $this->rewriteTome->invoke($this->createCommand(), $book);

        self::assertSame('4', $book->getTome());
    }

    public function testCommandExecutionReturnsSuccess(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);

        $factory = $this->createStub(ScrapperFactory::class);

        $tester = new CommandTester(new BookBatchCommand($em, $factory));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
    }

    private function createCommand(): BookBatchCommand
    {
        return new BookBatchCommand(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(ScrapperFactory::class),
        );
    }
}
