<?php

namespace App\EventListener;

use App\Entity\Book;
use App\Repository\BookRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Cache HTTP de la bibliothèque : ETag calculé sur un hash de version de la
 * collection de livres, revalidé à chaque requête (304 si inchangé).
 *
 * Le 304 est décidé avant l'exécution du contrôleur : on court-circuite toute
 * la pipeline (provider, hydratation ORM, sérialisation) quand le client
 * présente déjà la bonne représentation.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER)]
#[AsEventListener(event: KernelEvents::RESPONSE)]
class BookCollectionCacheListener
{
    private const CACHE_RULES = [
        'private' => true,
        'max_age' => 0,
        'must_revalidate' => true,
    ];

    public function __construct(private readonly BookRepository $bookRepository)
    {
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $request = $event->getRequest();

        // L'opération n'est pas peuplée dans les attributs à ce stade : on se
        // repère à la route API Platform de collection (`_api_resource_class`
        // + nom d'opération terminant par `_get_collection`).
        if (!$request->isMethodCacheable() || Book::class !== $request->attributes->get('_api_resource_class')) {
            return;
        }

        $operationName = (string) $request->attributes->get('_api_operation_name');

        if (!str_ends_with($operationName, '_get_collection')) {
            return;
        }

        $etag = $this->computeEtag($request);

        $response = new Response();
        $response->setEtag($etag);
        $response->setCache(self::CACHE_RULES);

        if ($response->isNotModified($request)) {
            // Court-circuit via un contrôleur jetable : HTTP Kernel n'exécute
            // pas le pipeline API Platform (provider, hydratation, normalisation).
            $event->setController(static fn (): Response => $response);

            return;
        }

        $request->attributes->set('_books_etag', $etag);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $etag = $request->attributes->get('_books_etag');

        if (null === $etag) {
            return;
        }

        $response = $event->getResponse();

        if (Response::HTTP_OK !== $response->getStatusCode()) {
            return;
        }

        $response->setEtag($etag);
        $response->setCache(self::CACHE_RULES);
        // L'API Platform pose par défaut `no-cache` : redondant avec
        // `max-age=0, must-revalidate`, on l'aligne sur les règles du cache.
        $response->headers->removeCacheControlDirective('no-cache');
    }

    private function computeEtag(Request $request): string
    {
        // L'ETag est propre à une représentation : la requête (variante) est
        // incluse pour qu'une URL différente ne partage pas un 304 erroné.
        return md5($this->bookRepository->libraryVersionHash() . $request->getRequestUri());
    }
}