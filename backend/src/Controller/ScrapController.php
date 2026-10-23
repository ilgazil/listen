<?php
namespace App\Controller;

use App\Entity\Book;
use App\Service\Scraping\Audible\AudibleScraper;
use App\Service\Scraping\Lizzie\LizzieScraper;
use App\Service\Scraping\ScraperInterface;
use App\Service\Scraping\SearchRanker;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;

class ScrapController
{
    #[Route('/api/scrap', name: 'api_scrap', methods: ['GET'])]
    public function __invoke(
        Request $request,
        LizzieScraper $lizzie,
        AudibleScraper $audible,
        SerializerInterface $serializer,
        LoggerInterface $logger,
    ): JsonResponse {
        $pattern = $request->query->get('pattern');

        if (!$pattern) {
            return new JsonResponse([]);
        }

        // Normalisation multibyte partagée avec les scrapers (accents, apostrophes,
        // ponctuation) — l'ancienne forme (\W sans flag u) scindait les accents en octets.
        $pattern = SearchRanker::normalize($pattern);

        if ('' === $pattern) {
            return new JsonResponse([]);
        }

        // Un scraper en échec (réseau, anti-bot…) ne doit pas priver des résultats de l'autre.
        $books = SearchRanker::merge(
            $pattern,
            $this->search($lizzie, $pattern, $logger),
            $this->search($audible, $pattern, $logger),
        );

        return new JsonResponse(
            $serializer->normalize($books, null, [
                'groups' => ['book:read'],
                'iri' => false,
                'skip_null_values' => true,
                'api_platform_disable' => true,
            ]),
        );
    }

    /**
     * Recherche auprès d'un scraper, sans jamais propager l'échec : au pire [], le
     * résultat de l'autre source est conservé.
     *
     * @return array<Book>
     */
    private function search(ScraperInterface $scraper, string $pattern, LoggerInterface $logger): array
    {
        try {
            return $scraper->search($pattern);
        } catch (Throwable $exception) {
            $logger->error('Échec de la recherche de métadonnées', [
                'scraper' => $scraper::class,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }
}
