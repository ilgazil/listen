<?php

namespace App\Tests\Service\Notification;

use App\Entity\Book;
use App\Service\Notification\Discord\DiscordWebhook;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class DiscordWebhookTest extends TestCase
{
    private const WEBHOOK = 'https://example.test/webhook';

    protected function setUp(): void
    {
        $_ENV['DISCORD_NEW_BOOK_WEBHOOK'] = self::WEBHOOK;
        $_ENV['DISCORD_SENTRY_WEBHOOK'] = self::WEBHOOK;
        unset($_ENV['APP_BASE_URL']);
    }

    protected function tearDown(): void
    {
        unset($_ENV['DISCORD_NEW_BOOK_WEBHOOK'], $_ENV['DISCORD_SENTRY_WEBHOOK'], $_ENV['APP_BASE_URL']);
    }

    public function testAvailableBuildsDownloadUrlFromRequest(): void
    {
        $webhook = new RecordingWebhook(new RequestStack(), 'https://listen.test');
        $webhook->available($this->createBook('abc123'));

        self::assertSame('https://listen.test/api/download/abc123', $webhook->lastContent['embeds'][0]['url']);
    }

    public function testUploadCutBuildsOrphansUrlFromRequest(): void
    {
        $webhook = new RecordingWebhook(new RequestStack(), 'https://listen.test');
        $webhook->uploadCut($this->createBook('abc123'));

        self::assertSame('https://listen.test/orphans', $webhook->lastContent['embeds'][0]['url']);
    }

    public function testAppBaseUrlOverridesRequest(): void
    {
        $_ENV['APP_BASE_URL'] = 'https://override.example/';

        $webhook = new RecordingWebhook(new RequestStack(), 'https://listen.test');
        $webhook->available($this->createBook('abc123'));

        self::assertSame('https://override.example/api/download/abc123', $webhook->lastContent['embeds'][0]['url']);
    }

    public function testWithoutRequestAndWithoutConfigOmitsUrl(): void
    {
        $webhook = new RecordingWebhook(new RequestStack());
        $webhook->available($this->createBook('abc123'));

        self::assertArrayNotHasKey('url', $webhook->lastContent['embeds'][0]);
    }

    public function testErrorIsSilentlyIgnoredWhenWebhookMissing(): void
    {
        unset($_ENV['DISCORD_SENTRY_WEBHOOK']);

        $webhook = new RecordingWebhook(new RequestStack(), 'https://listen.test');
        $webhook->error(new \RuntimeException('boom'));

        self::assertNull($webhook->lastContent);
    }

    private function createBook(string $id): Book
    {
        $book = new Book();
        $book->setId($id)
            ->setTitle('Livre')
            ->setCover('https://example.com/c.jpg')
            ->setRuntime('01:00')
            ->setRatings(0.0)
            ->setNarrators([]);

        return $book;
    }
}

final class RecordingWebhook extends DiscordWebhook
{
    public mixed $lastContent = null;

    public function __construct(RequestStack $requestStack, string $host = '')
    {
        if ($host !== '') {
            $requestStack->push(Request::create($host . '/'));
        }

        parent::__construct($requestStack);
    }

    protected function post(string $webhook, mixed $content): void
    {
        $this->lastContent = $content;
    }
}