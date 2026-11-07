<?php

namespace App\Tests\EventListener;

use App\Entity\Book;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BookCollectionCacheListenerTest extends WebTestCase
{
    /**
     * Régression : la colonne `tags` manquait au hash de version de
     * libraryVersionHash() — une passe app:scrap-tags ne changeait que `tags`,
     * le hash restait stable et les clients restaient en 304 sur une liste
     * sans tags (l'UI n'affichait jamais les tags fraîchement scrapés).
     */
    public function testTagsOnlyChangeInvalidatesEtag(): void
    {
        $client = static::createClient();
        // La BDD `:memory:` (sqlite) vit sur la connexion du kernel :
        // sans désactivation du reboot, une requête recrée un kernel neuf
        // (et une connexion vide) → « no such table: book » (cf. ApiControllerTest).
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        $book = (new Book())
            ->setId('etagtest01')
            ->setTitle('Un livre')
            ->setCover('https://example.com/c.jpg')
            ->setAuthor('Un auteur')
            ->setNarrators(['Un narrateur'])
            ->setSaga('Une saga')
            ->setTags(['Fantasy']);
        $em->persist($book);
        $em->flush();

        // Premier GET : 200 + ETag de la représentation courante.
        $client->request('GET', '/api/books?pagination=false');
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $etag = $response->getEtag();
        self::assertNotNull($etag);

        // Revalidation conditionnelle, représentation inchangée : 304.
        $client->request('GET', '/api/books?pagination=false', [], [], [
            'HTTP_IF_NONE_MATCH' => $etag,
        ]);
        self::assertSame(304, $client->getResponse()->getStatusCode());

        // Un changement UNIQUEMENT des tags doit faire bouger l'ETag → 200.
        // UPDATE SQL brut : après les requêtes, le resetter de services a vidé
        // l'EM (kernel.terminate) — l'entité PHP locale serait détachée et le
        // flush() silencieux.
        $em->getConnection()->executeStatement(
            "UPDATE book SET tags = 'Aventure' WHERE id = 'etagtest01'",
        );

        $client->request('GET', '/api/books?pagination=false', [], [], [
            'HTTP_IF_NONE_MATCH' => $etag,
        ]);
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Aventure', (string) $response->getContent());
    }
}
