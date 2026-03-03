<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260223250000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Entités Modele, Piece, Stock : catalogue pièces et stock par site.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof MySQLPlatform) {
            $this->addSql('CREATE TABLE modele (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(120) NOT NULL, constructeur VARCHAR(100) NOT NULL, reference VARCHAR(100) DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_modele_nom_constructeur (nom, constructeur), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('CREATE TABLE piece (id INT AUTO_INCREMENT NOT NULL, reference VARCHAR(80) NOT NULL, libelle VARCHAR(255) NOT NULL, type VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_piece_reference (reference), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('CREATE TABLE modele_piece (modele_id INT NOT NULL, piece_id INT NOT NULL, INDEX IDX_modele_piece_modele (modele_id), INDEX IDX_modele_piece_piece (piece_id), PRIMARY KEY(modele_id, piece_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('CREATE TABLE stock (id INT AUTO_INCREMENT NOT NULL, piece_id INT NOT NULL, site_id INT NOT NULL, quantite INT DEFAULT 0 NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_stock_piece_site (piece_id, site_id), INDEX idx_stock_site (site_id), INDEX idx_stock_piece (piece_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE modele_piece ADD CONSTRAINT FK_modele_piece_modele FOREIGN KEY (modele_id) REFERENCES modele (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE modele_piece ADD CONSTRAINT FK_modele_piece_piece FOREIGN KEY (piece_id) REFERENCES piece (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE stock ADD CONSTRAINT FK_stock_piece FOREIGN KEY (piece_id) REFERENCES piece (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE stock ADD CONSTRAINT FK_stock_site FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE imprimante ADD modele_id INT DEFAULT NULL');
            $this->addSql('CREATE INDEX idx_imprimante_modele ON imprimante (modele_id)');
            $this->addSql('ALTER TABLE imprimante ADD CONSTRAINT FK_impr_modele FOREIGN KEY (modele_id) REFERENCES modele (id) ON DELETE SET NULL');
        } else {
            $this->addSql('CREATE TABLE modele (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nom VARCHAR(120) NOT NULL, constructeur VARCHAR(100) NOT NULL, reference VARCHAR(100) DEFAULT NULL, created_at DATETIME NOT NULL)');
            $this->addSql('CREATE UNIQUE INDEX uniq_modele_nom_constructeur ON modele (nom, constructeur)');
            $this->addSql('CREATE TABLE piece (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, reference VARCHAR(80) NOT NULL, libelle VARCHAR(255) NOT NULL, type VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL)');
            $this->addSql('CREATE UNIQUE INDEX uniq_piece_reference ON piece (reference)');
            $this->addSql('CREATE TABLE modele_piece (modele_id INT NOT NULL, piece_id INT NOT NULL, PRIMARY KEY(modele_id, piece_id), CONSTRAINT FK_modele_piece_modele FOREIGN KEY (modele_id) REFERENCES modele (id) ON DELETE CASCADE, CONSTRAINT FK_modele_piece_piece FOREIGN KEY (piece_id) REFERENCES piece (id) ON DELETE CASCADE)');
            $this->addSql('CREATE INDEX IDX_modele_piece_modele ON modele_piece (modele_id)');
            $this->addSql('CREATE INDEX IDX_modele_piece_piece ON modele_piece (piece_id)');
            $this->addSql('CREATE TABLE stock (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, piece_id INT NOT NULL, site_id INT NOT NULL, quantite INT DEFAULT 0 NOT NULL, updated_at DATETIME NOT NULL, CONSTRAINT FK_stock_piece FOREIGN KEY (piece_id) REFERENCES piece (id) ON DELETE CASCADE, CONSTRAINT FK_stock_site FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE CASCADE)');
            $this->addSql('CREATE UNIQUE INDEX uniq_stock_piece_site ON stock (piece_id, site_id)');
            $this->addSql('CREATE INDEX idx_stock_site ON stock (site_id)');
            $this->addSql('CREATE INDEX idx_stock_piece ON stock (piece_id)');
            $this->addSql('ALTER TABLE imprimante ADD COLUMN modele_id INTEGER DEFAULT NULL REFERENCES modele (id) ON DELETE SET NULL');
            $this->addSql('CREATE INDEX idx_imprimante_modele ON imprimante (modele_id)');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof MySQLPlatform) {
            $this->addSql('ALTER TABLE imprimante DROP FOREIGN KEY FK_impr_modele');
            $this->addSql('DROP INDEX idx_imprimante_modele ON imprimante');
            $this->addSql('ALTER TABLE imprimante DROP COLUMN modele_id');
            $this->addSql('ALTER TABLE stock DROP FOREIGN KEY FK_stock_site');
            $this->addSql('ALTER TABLE stock DROP FOREIGN KEY FK_stock_piece');
            $this->addSql('DROP TABLE stock');
            $this->addSql('ALTER TABLE modele_piece DROP FOREIGN KEY FK_modele_piece_piece');
            $this->addSql('ALTER TABLE modele_piece DROP FOREIGN KEY FK_modele_piece_modele');
            $this->addSql('DROP TABLE modele_piece');
            $this->addSql('DROP TABLE piece');
            $this->addSql('DROP TABLE modele');
        } else {
            $this->addSql('DROP INDEX IF EXISTS idx_imprimante_modele');
            $this->addSql('DROP TABLE stock');
            $this->addSql('DROP TABLE modele_piece');
            $this->addSql('DROP TABLE piece');
            $this->addSql('DROP TABLE modele');
            $this->addSql('CREATE TEMPORARY TABLE imprimante_backup AS SELECT id, site_id, numero_serie, modele, constructeur, emplacement, gerer, color, ip_address, created_at, updated_at FROM imprimante');
            $this->addSql('DROP TABLE imprimante');
            $this->addSql('CREATE TABLE imprimante (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, site_id INT DEFAULT NULL, numero_serie VARCHAR(100) NOT NULL, modele VARCHAR(255) NOT NULL, constructeur VARCHAR(100) NOT NULL, emplacement VARCHAR(255) DEFAULT NULL, gerer BOOLEAN DEFAULT 1 NOT NULL, color BOOLEAN DEFAULT 1 NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, CONSTRAINT FK_impr_site FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE SET NULL)');
            $this->addSql('INSERT INTO imprimante SELECT id, site_id, numero_serie, modele, constructeur, emplacement, gerer, color, ip_address, created_at, updated_at FROM imprimante_backup');
            $this->addSql('DROP TABLE imprimante_backup');
            $this->addSql('CREATE UNIQUE INDEX uniq_imprimante_numero_serie ON imprimante (numero_serie)');
            $this->addSql('CREATE INDEX idx_imprimante_site ON imprimante (site_id)');
        }
    }
}
