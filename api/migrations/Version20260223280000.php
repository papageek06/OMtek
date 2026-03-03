<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260223280000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Piece: ajout categorie, variant, nature. type déprécié (VARCHAR nullable).';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof MySQLPlatform) {
            $this->addSql('ALTER TABLE piece ADD categorie VARCHAR(30) NOT NULL DEFAULT \'AUTRE\', ADD variant VARCHAR(15) DEFAULT NULL, ADD nature VARCHAR(15) DEFAULT NULL');
            $this->addSql('ALTER TABLE piece MODIFY type VARCHAR(50) DEFAULT NULL');
            $this->addSql('CREATE INDEX idx_piece_categorie ON piece (categorie)');
            $this->addSql('CREATE INDEX idx_piece_variant ON piece (variant)');
        } else {
            $this->addSql('ALTER TABLE piece ADD COLUMN categorie VARCHAR(30) NOT NULL DEFAULT \'AUTRE\'');
            $this->addSql('ALTER TABLE piece ADD COLUMN variant VARCHAR(15) DEFAULT NULL');
            $this->addSql('ALTER TABLE piece ADD COLUMN nature VARCHAR(15) DEFAULT NULL');
            $this->addSql('ALTER TABLE piece RENAME COLUMN type TO type_old');
            $this->addSql('ALTER TABLE piece ADD COLUMN type VARCHAR(50) DEFAULT NULL');
            $this->addSql('UPDATE piece SET type = type_old');
            $this->addSql('ALTER TABLE piece DROP COLUMN type_old');
            $this->addSql('CREATE INDEX idx_piece_categorie ON piece (categorie)');
            $this->addSql('CREATE INDEX idx_piece_variant ON piece (variant)');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof MySQLPlatform) {
            $this->addSql('DROP INDEX idx_piece_variant ON piece');
            $this->addSql('DROP INDEX idx_piece_categorie ON piece');
            $this->addSql('ALTER TABLE piece DROP COLUMN nature');
            $this->addSql('ALTER TABLE piece DROP COLUMN variant');
            $this->addSql('ALTER TABLE piece DROP COLUMN categorie');
            $this->addSql('ALTER TABLE piece MODIFY type VARCHAR(30) NOT NULL');
        } else {
            $this->addSql('DROP INDEX idx_piece_variant');
            $this->addSql('DROP INDEX idx_piece_categorie');
            $this->addSql('ALTER TABLE piece DROP COLUMN nature');
            $this->addSql('ALTER TABLE piece DROP COLUMN variant');
            $this->addSql('ALTER TABLE piece DROP COLUMN categorie');
            $this->addSql('ALTER TABLE piece ADD COLUMN type_backup VARCHAR(30) NOT NULL DEFAULT \'autre\'');
            $this->addSql('UPDATE piece SET type_backup = COALESCE(type, \'autre\')');
            $this->addSql('ALTER TABLE piece DROP COLUMN type');
            $this->addSql('ALTER TABLE piece RENAME COLUMN type_backup TO type');
        }
    }
}
