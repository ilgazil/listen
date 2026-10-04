<?php

namespace App\Tests\Service\Notification;

use App\Service\Notification\Discord\Messages\ErrorMessage;
use PHPUnit\Framework\TestCase;

class ErrorMessageTest extends TestCase
{
    public function testContentContainsExceptionDetails(): void
    {
        $exception = new \RuntimeException('Erreur critique', 500);
        $message = (new ErrorMessage($exception))->getContent();
        $embed = $message['embeds'][0];

        self::assertSame('Erreur runtime PHP', $embed['title']);
        self::assertSame(hexdec('e03131'), $embed['color']);
        self::assertStringContainsString('RuntimeException: Erreur critique', $embed['description']);

        $fieldNames = array_column($embed['fields'], 'name');
        self::assertContains('Code', $fieldNames);
        self::assertContains('Fichier', $fieldNames);

        $codeField = $embed['fields'][array_search('Code', $fieldNames)];
        self::assertSame('500', $codeField['value']);

        $fileField = $embed['fields'][array_search('Fichier', $fieldNames)];
        self::assertStringContainsString('ErrorMessageTest.php:', $fileField['value']);
    }

    public function testLongMessageIsTruncated(): void
    {
        $exception = new \RuntimeException(str_repeat('a', 5000));
        $message = (new ErrorMessage($exception))->getContent();

        self::assertLessThanOrEqual(2000, mb_strlen($message['embeds'][0]['description']));
    }

    public function testStringErrorContent(): void
    {
        $message = (new ErrorMessage('1Fichier upload failed - boom'))->getContent();
        $embed = $message['embeds'][0];

        self::assertSame('Erreur runtime PHP', $embed['title']);
        self::assertSame('1Fichier upload failed - boom', $embed['description']);
        self::assertSame([], $embed['fields']);
    }

    public function testLongStringErrorIsTruncated(): void
    {
        $message = (new ErrorMessage(str_repeat('b', 5000)))->getContent();

        self::assertLessThanOrEqual(2000, mb_strlen($message['embeds'][0]['description']));
    }
}