<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260223230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tables site, imprimante, rapport_imprimante pour import CSV.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof MySQLPlatform) {
            $this->addSql('CREATE TABLE site (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_site_nom (nom), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('CREATE TABLE imprimante (id INT AUTO_INCREMENT NOT NULL, site_id INT DEFAULT NULL, numero_serie VARCHAR(100) NOT NULL, modele VARCHAR(255) NOT NULL, constructeur VARCHAR(100) NOT NULL, emplacement VARCHAR(255) DEFAULT NULL, gerer TINYINT(1) DEFAULT 1 NOT NULL, color TINYINT(1) DEFAULT 1 NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_imprimante_numero_serie (numero_serie), INDEX idx_imprimante_site (site_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE imprimante ADD CONSTRAINT FK_impr_site FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE SET NULL');
            $this->addSql('CREATE TABLE rapport_imprimante (id INT AUTO_INCREMENT NOT NULL, imprimante_id INT NOT NULL, date_scan DATETIME DEFAULT NULL, last_scan_date DATETIME DEFAULT NULL, mono_life_count VARCHAR(50) DEFAULT NULL, color_life_count VARCHAR(50) DEFAULT NULL, fax_count VARCHAR(50) DEFAULT NULL, small_mono_count VARCHAR(50) DEFAULT NULL, large_mono_count VARCHAR(50) DEFAULT NULL, small_color_count VARCHAR(50) DEFAULT NULL, large_color_count VARCHAR(50) DEFAULT NULL, black_level VARCHAR(20) DEFAULT NULL, cyan_level VARCHAR(20) DEFAULT NULL, magenta_level VARCHAR(20) DEFAULT NULL, yellow_level VARCHAR(20) DEFAULT NULL, waste_level VARCHAR(20) DEFAULT NULL, black_coverage VARCHAR(20) DEFAULT NULL, cyan_coverage VARCHAR(20) DEFAULT NULL, magenta_coverage VARCHAR(20) DEFAULT NULL, yellow_coverage VARCHAR(20) DEFAULT NULL, black_depletion_date VARCHAR(20) DEFAULT NULL, cyan_depletion_date VARCHAR(20) DEFAULT NULL, magenta_depletion_date VARCHAR(20) DEFAULT NULL, yellow_depletion_date VARCHAR(20) DEFAULT NULL, black_impression_remaining VARCHAR(50) DEFAULT NULL, cyan_impression_remaining VARCHAR(50) DEFAULT NULL, magenta_impression_remaining VARCHAR(50) DEFAULT NULL, yellow_impression_remaining VARCHAR(50) DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_rapport_impr_date (imprimante_id, date_scan), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE rapport_imprimante ADD CONSTRAINT FK_rapport_impr FOREIGN KEY (imprimante_id) REFERENCES imprimante (id) ON DELETE CASCADE');
        } else {
            $this->addSql('CREATE TABLE site (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, nom VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL)');
            $this->addSql('CREATE UNIQUE INDEX uniq_site_nom ON site (nom)');
            $this->addSql('CREATE TABLE imprimante (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, site_id INT DEFAULT NULL, numero_serie VARCHAR(100) NOT NULL, modele VARCHAR(255) NOT NULL, constructeur VARCHAR(100) NOT NULL, emplacement VARCHAR(255) DEFAULT NULL, gerer BOOLEAN DEFAULT 1 NOT NULL, color BOOLEAN DEFAULT 1 NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, CONSTRAINT FK_impr_site FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE SET NULL)');
            $this->addSql('CREATE UNIQUE INDEX uniq_imprimante_numero_serie ON imprimante (numero_serie)');
            $this->addSql('CREATE INDEX idx_imprimante_site ON imprimante (site_id)');
            $this->addSql('CREATE TABLE rapport_imprimante (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, imprimante_id INT NOT NULL, date_scan DATETIME DEFAULT NULL, last_scan_date DATETIME DEFAULT NULL, mono_life_count VARCHAR(50) DEFAULT NULL, color_life_count VARCHAR(50) DEFAULT NULL, fax_count VARCHAR(50) DEFAULT NULL, small_mono_count VARCHAR(50) DEFAULT NULL, large_mono_count VARCHAR(50) DEFAULT NULL, small_color_count VARCHAR(50) DEFAULT NULL, large_color_count VARCHAR(50) DEFAULT NULL, black_level VARCHAR(20) DEFAULT NULL, cyan_level VARCHAR(20) DEFAULT NULL, magenta_level VARCHAR(20) DEFAULT NULL, yellow_level VARCHAR(20) DEFAULT NULL, waste_level VARCHAR(20) DEFAULT NULL, black_coverage VARCHAR(20) DEFAULT NULL, cyan_coverage VARCHAR(20) DEFAULT NULL, magenta_coverage VARCHAR(20) DEFAULT NULL, yellow_coverage VARCHAR(20) DEFAULT NULL, black_depletion_date VARCHAR(20) DEFAULT NULL, cyan_depletion_date VARCHAR(20) DEFAULT NULL, magenta_depletion_date VARCHAR(20) DEFAULT NULL, yellow_depletion_date VARCHAR(20) DEFAULT NULL, black_impression_remaining VARCHAR(50) DEFAULT NULL, cyan_impression_remaining VARCHAR(50) DEFAULT NULL, magenta_impression_remaining VARCHAR(50) DEFAULT NULL, yellow_impression_remaining VARCHAR(50) DEFAULT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_rapport_impr FOREIGN KEY (imprimante_id) REFERENCES imprimante (id) ON DELETE CASCADE)');
            $this->addSql('CREATE INDEX idx_rapport_impr_date ON rapport_imprimante (imprimante_id, date_scan)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rapport_imprimante');
        $this->addSql('DROP TABLE imprimante');
        $this->addSql('DROP TABLE site');
    }
}
