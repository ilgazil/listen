<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Delta pour aligner le schéma legacy sur le modèle temporaire :
 * - `tome` VARCHAR(2) → VARCHAR(40) (varchar(40) en prod, no-op) ;
 * - `saga` VARCHAR(40) → VARCHAR(255) ;
 * - `ratings` VARCHAR(5) → DOUBLE (nullable).
 *
 * `narrators` reste une colonne chaîne : le modèle temporaire expose un tableau
 * en API mais stocke encore une chaîne CSV (voir backend/SPECS.md § 5).
 *
 * À lancer MANUELLEMENT : php bin/console doctrine:migrations:migrate
 */
final class Version20260808000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delta schema : tome VARCHAR(40), saga VARCHAR(255), ratings DOUBLE (à lancer manuellement).';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE book MODIFY tome VARCHAR(40) NULL');
            $this->addSql('ALTER TABLE book MODIFY saga VARCHAR(255) NOT NULL');
            $this->addSql('ALTER TABLE book MODIFY ratings DOUBLE PRECISION NULL');
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $this->addSql('ALTER TABLE book ALTER tome TYPE VARCHAR(40)');
            $this->addSql('ALTER TABLE book ALTER tome DROP NOT NULL');
            $this->addSql('ALTER TABLE book ALTER saga TYPE VARCHAR(255)');
            $this->addSql('ALTER TABLE book ALTER ratings TYPE DOUBLE PRECISION');
            $this->addSql('ALTER TABLE book ALTER ratings DROP NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE book MODIFY tome VARCHAR(2) NULL');
            $this->addSql('ALTER TABLE book MODIFY saga VARCHAR(40) NOT NULL');
            $this->addSql('ALTER TABLE book MODIFY ratings VARCHAR(5) NOT NULL');
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $this->addSql('ALTER TABLE book ALTER tome TYPE VARCHAR(2)');
            $this->addSql('ALTER TABLE book ALTER saga TYPE VARCHAR(40)');
            $this->addSql('ALTER TABLE book ALTER ratings TYPE VARCHAR(5)');
            $this->addSql('ALTER TABLE book ALTER ratings SET NOT NULL');
        }
    }
}
