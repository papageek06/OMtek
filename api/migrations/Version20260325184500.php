<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325184500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout du flag site.is_hidden pour masquer des sites aux techniciens.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site ADD is_hidden TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX idx_site_is_hidden ON site (is_hidden)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_site_is_hidden ON site');
        $this->addSql('ALTER TABLE site DROP is_hidden');
    }
}

