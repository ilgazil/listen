<?php
namespace App\Controller;

use App\Service\Scraping\Audible\AudibleScraper;
use App\Service\Scraping\Lizzie\LizzieScraper;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

class ScrapController
{
    #[Route('/api/scrap', name: 'api_scrap', methods: ['GET'])]
    public function __invoke(Request $request, LizzieScraper $lizzie, AudibleScraper $audible, SerializerInterface $serializer): JsonResponse
    {
        $pattern = $request->query->get('pattern');

        if (!$pattern) {
            return new JsonResponse([]);
        }

        $pattern = trim(strtolower(preg_replace('/\s+/', ' ', preg_replace('/\W+/', ' ', $pattern))));

        $books = array_values(
            array_merge(
                $lizzie->search($pattern),
                $audible->search($pattern),
            ),
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
}
