<?php
namespace App\Controller;

use App\Entity\Book;
use App\Service\Scraping\AntiBotException;
use App\Service\Scraping\Audible\AudibleScraper;
use App\Service\Scraping\Lizzie\LizzieScraper;
use App\Service\Scraping\ScraperInterface;
use App\Service\Scraping\SearchRanker;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
     * Scrap direct de la fiche d'une URL produit collée par l'utilisateur
     * (toggle « URL » du formulaire) : chaque scraper tente fromUrl(), la
     * première fiche non nulle est renvoyée seule. Aucune fiche (hôte inconnu,
     * page non produit) → [] : le formulaire affiche son message dédié et la
     * saisie manuelle reste possible. Mur anti-bot → 502 + le message du
     * scraper (affiché tel quel sur le formulaire, réessayable).
     */
    #[Route('/api/scrap/url', name: 'api_scrap_url', methods: ['GET'])]
    public function url(
        Request $request,
        LizzieScraper $lizzie,
        AudibleScraper $audible,
        SerializerInterface $serializer,
        LoggerInterface $logger,
    ): JsonResponse {
        $url = trim((string) $request->query->get('url'));

        if ($url === '') {
            return new JsonResponse([]);
        }

        $blocked = null;

        foreach ([$audible, $lizzie] as $scraper) {
            try {
                $book = $scraper->fromUrl($url);
            } catch (AntiBotException $exception) {
                // Mur anti-bot : mémorisé (erreur explicite en fin de requête,
                // seule si aucune fiche n'a pu être obtenue d'un autre scraper).
                $blocked ??= $exception;

                $book = null;
            } catch (Throwable $exception) {
                // Un échec (réseau…) ne doit jamais faire échouer la requête.
                $logger->error('Échec du scrap d\'une URL de fiche', [
                    'scraper' => $scraper::class,
                    'error' => $exception->getMessage(),
                ]);

                $book = null;
            }

            if ($book !== null) {
                return new JsonResponse(
                    $serializer->normalize([$book], null, [
                        'groups' => ['book:read'],
                        'iri' => false,
                        'skip_null_values' => true,
                        'api_platform_disable' => true,
                    ]),
                );
            }
        }

        if ($blocked !== null) {
            return new JsonResponse(['error' => $blocked->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse([]);
    }

    #[Route('/api/scrap/tags', name: 'api_scrap_tags', methods: ['GET'])]
    public function tags(Request $request, AudibleScraper $audible, LoggerInterface $logger): JsonResponse
    {
        $scraper = (string) $request->query->get('scraper');
        $scrapId = trim((string) $request->query->get('scrap_id'));

        // Les tags n'existent que sur la fiche Audible (Lizzie n'en expose pas).
        if ($scraper !== AudibleScraper::$NAME || $scrapId === '') {
            return new JsonResponse(['error' => 'Unknown scraper or missing scrap_id.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return new JsonResponse(['tags' => $audible->tags($scrapId)]);
        } catch (Throwable $exception) {
            $logger->error('Échec du scrap des tags', [
                'scraper' => AudibleScraper::class,
                'scrap_id' => $scrapId,
                'error' => $exception->getMessage(),
            ]);

            // Un échec (réseau, anti-bot…) renvoie une liste vide : le champ reste
            // éditable à la main côté frontend.
            return new JsonResponse(['tags' => []]);
        }
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
