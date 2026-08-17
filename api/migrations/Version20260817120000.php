<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260817120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Elargit l identifiant Exchange des contacts pour eviter les collisions a l import.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE contact MODIFY exchange_id VARCHAR(512) DEFAULT NULL');
            return;
        }

        if ($platform instanceof SqlitePlatform) {
            return;
        }

        $this->abortIf(true, 'Plateforme non supportee pour cette migration.');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE contact MODIFY exchange_id VARCHAR(255) DEFAULT NULL');
            return;
        }

        if ($platform instanceof SqlitePlatform) {
            return;
        }

        $this->abortIf(true, 'Plateforme non supportee pour cette migration.');
    }
}
