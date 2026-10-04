<?php

namespace App\Monolog;

use anlutro\cURL\cURL;
use App\Service\Notification\Discord\Messages\ErrorMessage;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class DiscordWebhookHandler extends AbstractProcessingHandler
{
    private cURL $client;

    public function __construct()
    {
        parent::__construct(Logger::ERROR);
        $this->client = new cURL();
    }

    protected function write(LogRecord $record): void
    {
        $webhook = $_ENV['DISCORD_SENTRY_WEBHOOK'] ?? '';

        if ($webhook === '') {
            return;
        }

        $exception = $record->context['exception'] ?? null;

        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return;
        }

        $error = $exception instanceof Throwable ? $exception : (string) $record->message;

        $this->client->jsonPost($webhook, (new ErrorMessage($error))->getContent());
    }
}