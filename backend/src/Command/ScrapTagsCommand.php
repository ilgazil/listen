<?php

namespace App\Command;

use App\Entity\Book;
use App\Repository\BookRepository;
use App\Service\Scraping\Audible\AudibleScraper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:scrap-tags',
    description: 'Fetch tags from the Audible pages of the library books and update them.',
    hidden: false,
)]
class ScrapTagsCommand extends Command
{
    // Pause entre deux fetches : les fiches Audible répondent parfois par un mur
    // 503 anti rate-limiting (sans status HTTP d'échec côté cURL) — espacer les
    // appels limite le risque de déclencher le mur (les retries du scraper
    // restent le filet de sécurité).
    protected const FETCH_PAUSE_US = 500000;

    public function __construct(
        protected EntityManagerInterface $entityManager,
        protected BookRepository $repository,
        protected AudibleScraper $scraper,
        ?string $name = null,
    )
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the changes without saving them.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of Audible pages to fetch (0 = no limit).', '0')
            ->addOption('scrap-id', null, InputOption::VALUE_REQUIRED, 'Only process the book matching this Audible ASIN.', '')
            ->addOption('empty-only', null, InputOption::VALUE_NONE, 'Only fetch books that have no tags yet — re-runs after failures only fill the gaps.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = max(0, (int) $input->getOption('limit'));
        $scrapId = (string) $input->getOption('scrap-id');
        $emptyOnly = (bool) $input->getOption('empty-only');

        // Les tags n'existent que sur les fiches Audible (Lizzie n'en expose pas).
        $criteria = ['scraper' => 'audible'];
        if ($scrapId !== '') {
            $criteria['scrap_id'] = $scrapId;
        }

        /** @var array<Book> $books */
        $books = $this->repository->findBy($criteria);

        // Reprise : avec --empty-only, les livres déjà taggés sont écartés sans
        // consommer de fetch — une relance ne fetch que les manques (échecs de
        // mur, fiches restées vides). La comparaison triée ne protégeait que le
        // flush, jamais le fetch : sans ce filtre, une relance re-scrape tout.
        $tagged = 0;
        if ($emptyOnly) {
            $tagged = count($books);
            $books = array_values(array_filter($books, fn (Book $book) => $book->getTags() === []));
            $tagged -= count($books);
        }

        if ($books === []) {
            $output->writeln($emptyOnly && $tagged > 0
                ? sprintf('All %d book(s) already tagged, nothing to fetch.', $tagged)
                : 'No book to process.');

            return Command::SUCCESS;
        }

        $fetched = 0;
        $updated = 0;
        $skipped = 0;
        $failures = 0;

        foreach ($books as $book) {
            // La limite compte les fiches réellement fetchées : un livre sauté
            // (pas d'ASIN, fiche sans tags) ne consomme pas le quota.
            if ($limit > 0 && $fetched >= $limit) {
                break;
            }

            if ($book->getScrapId() === null || $book->getScrapId() === '') {
                $output->writeln(sprintf('[skip] %s — no scraper id.', $book->getTitle()));
                ++$skipped;
                continue;
            }

            // Un fetch a déjà eu lieu : on souffle avant le prochain.
            if ($fetched > 0) {
                $this->pause();
            }
            ++$fetched;

            try {
                $tags = $this->scraper->tags($book->getScrapId());
            } catch (\Throwable $e) {
                // Un échec de scraping n'efface jamais les tags du livre : on
                // passe au suivant (relancer la commande complètera).
                $output->writeln(sprintf('[error] %s — %s', $book->getTitle(), $e->getMessage()));
                ++$failures;
                continue;
            }

            // Comparaison triée : l'ordre des tags de la fiche n'est pas significatif.
            sort($tags);
            $current = $book->getTags();
            sort($current);

            // Fiche réellement sans genres (parsing à sec, fiche dépouillée…) :
            // on ne vide jamais des tags existants — mieux vaut des tags périmés
            // qu'un champ vidé. On liste les tags conservés : chaque livre
            // fetché affiche ses tags.
            if ($tags === []) {
                $output->writeln(sprintf(
                    '[skip] %s — no tags found on the page (kept: %s)',
                    $book->getTitle(),
                    implode(', ', $current) ?: 'none',
                ));
                ++$skipped;
                continue;
            }

            if ($tags === $current) {
                $output->writeln(sprintf('[ok] %s — up to date (%s).', $book->getTitle(), implode(', ', $tags)));
                continue;
            }

            $book->setTags($tags);
            // Flush par livre : une interruption en cours de traitement garde
            // tout ce qui a déjà été mis à jour (relance sûre et idempotente).
            if (!$dryRun) {
                $this->entityManager->flush();
            }

            $output->writeln(sprintf(
                '[%s] %s — %s (was: %s)%s',
                $dryRun ? 'change' : 'update',
                $book->getTitle(),
                implode(', ', $tags) ?: 'none',
                implode(', ', $current) ?: 'none',
                $dryRun ? ' (not saved)' : '',
            ));
            ++$updated;
        }

        $summary = sprintf(
            'Done: %d fetched, %d updated, %d skipped, %d failed.',
            $fetched,
            $updated,
            $skipped,
            $failures,
        );

        if ($emptyOnly) {
            $summary .= sprintf(' %d book(s) already tagged, not fetched.', $tagged);
        }

        $output->writeln($summary);

        return Command::SUCCESS;
    }

    /**
     * Souffle entre deux fetches (anti rate-limiting Audible) — no-op dans les
     * tests pour garder la suite rapide.
     */
    protected function pause(): void
    {
        usleep(self::FETCH_PAUSE_US);
    }
}
