<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajout de la colonne `tags` (liste de chaînes CSV, structure identique à `narrators`,
 * VARCHAR(255) NOT NULL) : tags de la fiche Audible — absents des pages recherche/série.
 *
 * À lancer MANUELLEMENT : php bin/console doctrine:migrations:migrate
 */
final class Version20261106000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout de la colonne tags VARCHAR(255) sur book (tags de la fiche Audible, à lancer manuellement).';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE book ADD tags VARCHAR(255) NOT NULL');
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $this->addSql('ALTER TABLE book ADD tags VARCHAR(255) NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE book DROP tags');
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $this->addSql('ALTER TABLE book DROP tags');
        }
    }
}
