<?php

namespace App\Command;

use App\Entity\Book;
use App\Service\Scraping\ScrapperFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:batch',
    description: 'Update books with a custom function.',
    hidden: false,
)]
class BookBatchCommand extends Command
{
    public function __construct(
        protected EntityManagerInterface $entityManager,
        protected ScrapperFactory $factory,
        ?string $name = null,
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $books = $this->entityManager->getRepository(Book::class)->findAll();

        foreach ($books as $book) {
            $this->rewriteTome($book);
        }

//        $this->entityManager->flush();

        return Command::SUCCESS;
    }

    protected function rewriteTome(Book $book): void
    {
        $tome = $book->getTome();

        if (!$tome) {
            return;
        }

        preg_match_all('/(?<value>[\d.]+)/m', $tome, $matches);

        $value = $matches['value'][0] ?? '';

        if ($tome === $value) {
            return;
        }

        $book->setTome($value);
    }
}
