<?php

namespace App\Tests\Entity;

use App\Entity\Book;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

class BookTest extends TestCase
{
    public function testNarratorsSerializesAsArray(): void
    {
        $book = new Book();
        $book->setNarrators(['Julien Rochefort', 'Marie Duplex']);

        self::assertSame(['Julien Rochefort', 'Marie Duplex'], $book->getNarrators());
    }

    public function testNarratorsStoredAsCsvStringWhileApiIsArray(): void
    {
        $book = new Book();
        $book->setTitle('T');
        $book->setNarrators([' A ', 'B', ' C']);

        $property = new \ReflectionProperty(Book::class, 'narrators');

        self::assertSame('A,B,C', $property->getValue($book));
        self::assertSame(['A', 'B', 'C'], $book->getNarrators());
    }

    public function testEmptyNarratorsIsEmptyArray(): void
    {
        $book = new Book();
        $book->setTitle('T');

        self::assertSame([], $book->getNarrators());
    }

    public function testGetFilename(): void
    {
        $book = new Book();
        $book->setAuthor('J.R.R. Tolkien');
        $book->setSaga('Terre du Milieu');
        $book->setTome('1.5');
        $book->setTitle('Le Seigneur des Anneaux');

        self::assertSame('j-r-r-tolkien-terre-du-milieu-1-5-le-seigneur-des-anneaux.zip', $book->getFilename());
    }

    public function testSerializeWithBookReadGroup(): void
    {
        $book = new Book();
        $book->setId('abc123');
        $book->setTitle('Titre');
        $book->setAuthor('Auteur');
        $book->setCover('https://example.com/c.jpg');
        $book->setSaga('Saga');
        $book->setTome('1');
        $book->setRuntime('10:30');
        $book->setRatings(4.6);
        $book->setScraper('audible');
        $book->setScrapId('ASIN1');
        $book->setNarrators(['Narrateur 1', 'Narrateur 2']);

        $serializer = new Serializer(
            [new ObjectNormalizer(new ClassMetadataFactory(new AttributeLoader()))],
            [new JsonEncoder()],
        );

        $data = $serializer->normalize($book, null, ['groups' => ['book:read'], 'skip_null_values' => true]);

        self::assertSame([
            'id' => 'abc123',
            'scraper' => 'audible',
            'scrap_id' => 'ASIN1',
            'title' => 'Titre',
            'cover' => 'https://example.com/c.jpg',
            'author' => 'Auteur',
            'narrators' => ['Narrateur 1', 'Narrateur 2'],
            'runtime' => '10:30',
            'ratings' => 4.6,
            'saga' => 'Saga',
            'tome' => '1',
        ], $data);
    }

    public function testFluentSettersReturnSelf(): void
    {
        $book = new Book();

        self::assertSame($book, $book->setId('x'));
        self::assertSame($book, $book->setTitle('T'));
        self::assertSame($book, $book->setAuthor('A'));
        self::assertSame($book, $book->setCover('C'));
        self::assertSame($book, $book->setSaga('S'));
        self::assertSame($book, $book->setTome('1'));
        self::assertSame($book, $book->setRuntime('10:00'));
        self::assertSame($book, $book->setRatings(4.0));
        self::assertSame($book, $book->setScraper('audible'));
        self::assertSame($book, $book->setScrapId('ASIN'));
        self::assertSame($book, $book->setNarrators(['N']));
    }

    public function testDefaultValuesAreNull(): void
    {
        $book = new Book();

        self::assertNull($book->getId());
        self::assertNull($book->getScraper());
        self::assertNull($book->getScrapId());
        self::assertNull($book->getTitle());
        self::assertNull($book->getCover());
        self::assertNull($book->getAuthor());
        self::assertNull($book->getRuntime());
        self::assertNull($book->getRatings());
        self::assertNull($book->getSaga());
        self::assertNull($book->getTome());
    }

    public function testGetFilenameWithoutSagaNorTome(): void
    {
        $book = new Book();
        $book->setAuthor('Auteur');
        $book->setTitle('Mon Livre');

        $filename = $book->getFilename();

        self::assertStringEndsWith('.zip', $filename);
        self::assertStringContainsString('auteur', $filename);
        self::assertStringContainsString('mon-livre', $filename);
    }

    public function testGetFilenameTransliteratesAccents(): void
    {
        $book = new Book();
        $book->setAuthor('J.R.R. Tolkien');
        $book->setSaga('Terre du Milieu');
        $book->setTome('1');
        $book->setTitle('La Communauté de l\'Anneau');

        $filename = $book->getFilename();

        self::assertSame('j-r-r-tolkien-terre-du-milieu-1-la-communaute-de-l-anneau.zip', $filename);
    }

    public function testSetNarratorsTrimsAndFiltersBlanks(): void
    {
        $book = new Book();
        $book->setNarrators(['  Alpha  ', '', '  Beta  ']);

        self::assertSame(['Alpha', 'Beta'], array_values($book->getNarrators()));
    }

    public function testSerializeSkipsNullValues(): void
    {
        $book = new Book();
        $book->setId('x');
        $book->setTitle('T');
        $book->setAuthor('A');
        $book->setCover('C');
        $book->setSaga('S');

        $serializer = new Serializer(
            [new ObjectNormalizer(new ClassMetadataFactory(new AttributeLoader()))],
            [new JsonEncoder()],
        );

        $data = $serializer->normalize($book, null, ['groups' => ['book:read'], 'skip_null_values' => true]);

        self::assertArrayNotHasKey('ratings', $data);
        self::assertArrayNotHasKey('tome', $data);
        self::assertArrayNotHasKey('runtime', $data);
        self::assertArrayNotHasKey('scraper', $data);
        self::assertArrayNotHasKey('scrap_id', $data);
    }
}
