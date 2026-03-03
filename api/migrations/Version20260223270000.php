<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260223270000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stock: site_id nullable pour stock général (site=NULL).';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof MySQLPlatform) {
            // 1. Rendre site_id nullable
            $this->addSql('ALTER TABLE stock MODIFY site_id INT DEFAULT NULL');
            // 2. Supprimer l'ancienne contrainte unique
            $this->addSql('ALTER TABLE stock DROP INDEX uniq_stock_piece_site');
            // 3. Nouvelle contrainte : une seule ligne par (piece_id, site_id ou NULL)
            // COALESCE(site_id, 0) : stock général (NULL) → 0, sites réels → id
            $this->addSql('CREATE UNIQUE INDEX uniq_stock_piece_site ON stock (piece_id, (COALESCE(site_id, 0)))');
        } else {
            $this->addSql('ALTER TABLE stock MODIFY site_id INT DEFAULT NULL');
            $this->addSql('DROP INDEX uniq_stock_piece_site ON stock');
            $this->addSql('CREATE UNIQUE INDEX uniq_stock_piece_site ON stock (piece_id, COALESCE(site_id, 0))');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof MySQLPlatform) {
            $this->addSql('ALTER TABLE stock DROP INDEX uniq_stock_piece_site');
            $this->addSql('DELETE FROM stock WHERE site_id IS NULL');
            $this->addSql('ALTER TABLE stock MODIFY site_id INT NOT NULL');
            $this->addSql('CREATE UNIQUE INDEX uniq_stock_piece_site ON stock (piece_id, site_id)');
        } else {
            $this->addSql('DROP INDEX uniq_stock_piece_site ON stock');
            $this->addSql('DELETE FROM stock WHERE site_id IS NULL');
            $this->addSql('ALTER TABLE stock MODIFY site_id INT NOT NULL');
            $this->addSql('CREATE UNIQUE INDEX uniq_stock_piece_site ON stock (piece_id, site_id)');
        }
    }
}
