<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260223240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la colonne ignorer à alerte (alerte réelle ou non).';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof MySQLPlatform) {
            $this->addSql('ALTER TABLE alerte ADD ignorer TINYINT(1) DEFAULT 0 NOT NULL');
        } else {
            $this->addSql('ALTER TABLE alerte ADD COLUMN ignorer BOOLEAN DEFAULT 0 NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof MySQLPlatform) {
            $this->addSql('ALTER TABLE alerte DROP COLUMN ignorer');
        } else {
            $this->addSql('ALTER TABLE alerte DROP COLUMN ignorer');
        }
    }
}
