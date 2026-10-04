<?php

namespace App\Tests\Monolog;

use App\Monolog\DiscordWebhookHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DiscordWebhookHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['DISCORD_SENTRY_WEBHOOK'] = '';
    }

    public function testLevelFiltering(): void
    {
        $handler = new DiscordWebhookHandler();
        $warning = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Warning,
            message: 'warning',
        );
        $error = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: 'error',
        );

        self::assertFalse($handler->isHandling($warning));
        self::assertTrue($handler->isHandling($error));
    }

    public function testErrorRecordWithEmptyWebhookDoesNotThrow(): void
    {
        $handler = new DiscordWebhookHandler();
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Error,
            message: 'boom',
            context: ['exception' => new \RuntimeException('boom')],
        );

        $handler->handle($record);
        $this->addToAssertionCount(1);
    }

    public function testErrorRecordWithoutExceptionDoesNotThrow(): void
    {
        $handler = new DiscordWebhookHandler();
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'request',
            level: Level::Critical,
            message: 'boom sans exception',
        );

        $handler->handle($record);
        $this->addToAssertionCount(1);
    }

    public function testClientErrorHttpExceptionIsIgnored(): void
    {
        $handler = new DiscordWebhookHandler();
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'request',
            level: Level::Error,
            message: '404',
            context: ['exception' => new NotFoundHttpException('No route found')],
        );

        $handler->handle($record);
        $this->addToAssertionCount(1);
    }
}