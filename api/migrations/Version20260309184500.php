<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260309184500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remplace l index fonctionnel du stock par une contrainte unique compatible Doctrine.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock DROP INDEX uniq_stock_piece_site_scope');
        $this->addSql('CREATE UNIQUE INDEX uniq_stock_piece_site_scope ON stock (piece_id, site_id, scope)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock DROP INDEX uniq_stock_piece_site_scope');
        $this->addSql('CREATE UNIQUE INDEX uniq_stock_piece_site_scope ON stock (piece_id, scope, (COALESCE(site_id, 0)))');
    }
}
