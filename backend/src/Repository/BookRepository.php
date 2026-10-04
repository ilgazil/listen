<?php

namespace App\Repository;

use App\Entity\Book;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Book>
 */
class BookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Book::class);
    }

/**
     * Renvoie l'identifiant de téléchargement de chaque livre connu.
     */
    public function findIds(): array
    {
        return $this->createQueryBuilder('b')
            ->select('b.id')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * Hash de version de la bibliothèque : change dès qu'un champ sérialisé évolue.
     *
     * Porté par une requête SQL brute (pas d'hydratation ORM ni de normalisation)
     * pour rester léger à chaque requête. Alimente l'ETag de GET /api/books.
     */
    public function libraryVersionHash(): string
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
            SELECT b.id,
                   b.scraper,
                   b.scrap_id,
                   b.title,
                   b.cover,
                   b.author,
                   b.runtime,
                   b.ratings,
                   b.saga,
                   b.tome,
                   b.narrators
            FROM book b
            ORDER BY b.id
            SQL,
        );

        return md5((string) json_encode($rows));
    }

    //    /**
    //     * @return Book[] Renvoie un tableau d'objets Book
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('b')
    //            ->andWhere('b.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('b.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Book
    //    {
    //        return $this->createQueryBuilder('b')
    //            ->andWhere('b.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
