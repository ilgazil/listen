<?php

namespace App\Command;

use App\Entity\Book;
use App\Service\Scraping\ScrapperFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(
    name: 'app:scrap-book',
    description: 'Update metadata from its scraper.',
    hidden: false,
)]
class ParseBookCommand extends Command
{
    public function __construct(
        protected EntityManagerInterface $entityManager,
        protected ScrapperFactory $factory,
        ?string $name = null,
    )
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addArgument('ids', InputArgument::IS_ARRAY, 'Book id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($input->getArgument('ids') as $id) {
            $book = $this->entityManager->getRepository(Book::class)->find($id);
            $scraper = $this->factory->fromBook($book);
            $newBook = $scraper->get($book->getScrapId());

            if (!$newBook) {
                continue;
            }

            $diff = $this->getDiff($book, $newBook);
            $updated = false;

            foreach ($diff as $key) {
                $getter = 'get' . ucfirst($key);
                $setter = 'set' . ucfirst($key);

                $helper = $this->getHelper('question');
                $question = new ConfirmationQuestion(
                    'Field ' . $key . ' updated: `' . call_user_func([$book, $getter]) . '` => `' . call_user_func([$newBook, $getter]) . '`',
                    true,
                );

                if ($helper->ask($input, $output, $question)) {
                    $updated = true;
                    call_user_func([$book, $setter], call_user_func([$newBook, $getter]));
                }
            }

            if (!$updated) {
                continue;
            }

            $this->entityManager->flush();

            $output->writeln($book->getTitle() . ' book updated!');
        }

        return Command::SUCCESS;
    }

    protected function getDiff(Book $base, Book $book): array
    {
        $diff = [];

        if ($base->getAuthor() !== $book->getAuthor()) {
            $diff[] = 'author';
        }

        if ($base->getTitle() !== $book->getTitle()) {
            $diff[] = 'title';
        }

        if ($base->getCover() !== $book->getCover()) {
            $diff[] = 'cover';
        }

        if ($base->getSaga() !== $book->getSaga()) {
            $diff[] = 'saga';
        }

        if ($base->getTome() !== $book->getTome()) {
            $diff[] = 'tome';
        }

        if ($base->getNarrators() !== $book->getNarrators()) {
            $diff[] = 'narrators';
        }

        if ($base->getRuntime() !== $book->getRuntime()) {
            $diff[] = 'runtime';
        }

        if ($base->getRatings() !== $book->getRatings()) {
            $diff[] = 'ratings';
        }

        return $diff;
    }
}
