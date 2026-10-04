<?php

namespace App\Tests\Service\Notification;

use App\Entity\Book;
use App\Service\Notification\Discord\Messages\AvailableBookMessage;
use PHPUnit\Framework\TestCase;

class AvailableBookMessageTest extends TestCase
{
    public function testFullBookContainsAllFields(): void
    {
        $book = $this->createBook(
            id: 'abc123',
            title: 'Le Hobbit',
            author: 'J.R.R. Tolkien',
            cover: 'https://example.com/cover.jpg',
            saga: 'Terre du Milieu',
            tome: '1',
            runtime: '11:30',
            ratings: 4.8,
        );

        $message = (new AvailableBookMessage($book, 'https://example.com/api/download/abc123'))->getContent();

        self::assertArrayHasKey('embeds', $message);
        $embed = $message['embeds'][0];

        self::assertSame('Le Hobbit', $embed['title']);
        self::assertSame(hexdec('f59e0b'), $embed['color']);
        self::assertSame('https://example.com/api/download/abc123', $embed['url']);
        self::assertSame('https://example.com/cover.jpg', $embed['image']['url']);

        $fieldNames = array_column($embed['fields'], 'name');
        self::assertContains('Saga', $fieldNames);
        self::assertContains('Durée', $fieldNames);
        self::assertContains('Évaluation', $fieldNames);

        $sagaField = $embed['fields'][array_search('Saga', $fieldNames)];
        self::assertSame('Terre du Milieu, 1', $sagaField['value']);
    }

    public function testBookWithoutSagaOmitsSagaField(): void
    {
        $book = $this->createBook(
            id: 'xyz',
            title: 'Standalone',
            author: 'Author',
            cover: 'https://example.com/c.jpg',
            saga: '',
            runtime: '05:00',
            ratings: 3.5,
        );

        $message = (new AvailableBookMessage($book))->getContent();
        $fieldNames = array_column($message['embeds'][0]['fields'], 'name');

        self::assertNotContains('Saga', $fieldNames);
    }

    public function testBookWithSagaButNoTomeShowsSagaOnly(): void
    {
        $book = $this->createBook(
            id: 'def',
            title: 'Livre',
            author: 'Auteur',
            cover: 'https://example.com/c.jpg',
            saga: 'Ma Saga',
            tome: '',
            runtime: '02:00',
            ratings: 4.0,
        );

        $message = (new AvailableBookMessage($book))->getContent();
        $fieldNames = array_column($message['embeds'][0]['fields'], 'name');
        $sagaIndex = array_search('Saga', $fieldNames);

        self::assertSame('Ma Saga', $message['embeds'][0]['fields'][$sagaIndex]['value']);
    }

    public function testRuntimeAndRatingsFieldsAreAlwaysPresent(): void
    {
        $book = $this->createBook(
            id: 'ghi',
            title: 'Livre',
            author: 'Auteur',
            cover: 'https://example.com/c.jpg',
            runtime: '',
            ratings: 0.0,
        );

        $message = (new AvailableBookMessage($book))->getContent();
        $fieldNames = array_column($message['embeds'][0]['fields'], 'name');

        self::assertContains('Durée', $fieldNames);
        self::assertContains('Évaluation', $fieldNames);
    }

    public function testBookWithoutProvidedUrlOmitsLink(): void
    {
        $book = $this->createBook(
            id: 'no-link',
            title: 'Livre',
            author: 'Auteur',
            cover: 'https://example.com/c.jpg',
            runtime: '01:00',
            ratings: 0.0,
        );

        $message = (new AvailableBookMessage($book))->getContent();

        self::assertArrayNotHasKey('url', $message['embeds'][0]);
    }

    private function createBook(
        string $id,
        string $title,
        string $author,
        string $cover,
        string $runtime,
        float $ratings,
        string $saga = '',
        string $tome = '',
    ): Book {
        $book = new Book();
        $book->setId($id)
            ->setTitle($title)
            ->setAuthor($author)
            ->setCover($cover)
            ->setRuntime($runtime)
            ->setRatings($ratings)
            ->setSaga($saga)
            ->setTome($tome)
            ->setNarrators(['Narrateur']);

        return $book;
    }
}
