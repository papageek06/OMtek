<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260223260000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute date_reference à stock (date inventaire ex. 31.12.2025).';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof MySQLPlatform) {
            $this->addSql('ALTER TABLE stock ADD date_reference DATE DEFAULT NULL');
        } else {
            $this->addSql('ALTER TABLE stock ADD COLUMN date_reference DATE DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock DROP COLUMN date_reference');
    }
}
