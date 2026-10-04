<?php

namespace App\Service\Notification\Discord;

use anlutro\cURL\cURL;
use App\Entity\Book;
use App\Service\Notification\Discord\Messages\AvailableBookMessage;
use App\Service\Notification\Discord\Messages\ErrorMessage;
use App\Service\Notification\Notifier;
use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;

class DiscordWebhook implements Notifier
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function available(Book $book): void
    {
        $url = $this->appUrl()?->path('/api/download/' . $book->getId());

        $this->post(
            $_ENV['DISCORD_NEW_BOOK_WEBHOOK'],
            (new AvailableBookMessage($book, $url))->getContent(),
        );
    }

    public function error(Throwable $exception): void
    {
        $webhook = $_ENV['DISCORD_SENTRY_WEBHOOK'] ?? '';

        if ($webhook === '') {
            return;
        }

        $this->post($webhook, (new ErrorMessage($exception))->getContent());
    }

    /**
     * Signale un envoi interrompu : même embed que le livre disponible, mais
     * pointant vers la page des fichiers orphelins où corriger le livre.
     */
    public function uploadCut(Book $book): void
    {
        $webhook = $_ENV['DISCORD_SENTRY_WEBHOOK'] ?? '';

        if ($webhook === '') {
            return;
        }

        $message = new AvailableBookMessage($book, $this->appUrl()?->path('/orphans'));

        $this->post($webhook, $message->getContent());
    }

    /**
     * Envoi brut vers un webhook — isolé pour être surchargé en test.
     */
    protected function post(string $webhook, mixed $content): void
    {
        (new cUrl())->jsonPost($webhook, $content);
    }

    /**
     * URL publique (schéma + hôte + préfixe de chemin éventuel) de
     * l'application.
     *
     * Source par défaut : la requête courante (au plus proche de l'utilisateur).
     * Surchargée par APP_BASE_URL, seul moyen hors contexte requête (CLI…).
     */
    private function appUrl(): ?BaseUrl
    {
        $override = trim((string) ($_ENV['APP_BASE_URL'] ?? ''));

        if ($override !== '') {
            return BaseUrl::from($override);
        }

        $request = $this->requestStack->getCurrentRequest();

        return $request ? BaseUrl::from($request->getSchemeAndHttpHost()) : null;
    }
}
