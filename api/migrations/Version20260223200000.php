<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260223200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table alerte pour stocker les alertes imprimantes (site, modèle, série, motif, état).';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof MySQLPlatform) {
            $this->addSql('CREATE TABLE alerte (id INT AUTO_INCREMENT NOT NULL, message_id VARCHAR(255) DEFAULT NULL, sujet VARCHAR(500) NOT NULL, expediteur VARCHAR(255) NOT NULL, recu_le DATETIME DEFAULT NULL, site VARCHAR(255) NOT NULL, modele_imprimante VARCHAR(255) NOT NULL, numero_serie VARCHAR(100) NOT NULL, motif_alerte LONGTEXT NOT NULL, etat VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('CREATE INDEX idx_alerte_site ON alerte (site)');
            $this->addSql('CREATE INDEX idx_alerte_numero_serie ON alerte (numero_serie)');
            $this->addSql('CREATE INDEX idx_alerte_recu_le ON alerte (recu_le)');
        } else {
            $this->addSql('CREATE TABLE alerte (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, message_id VARCHAR(255) DEFAULT NULL, sujet VARCHAR(500) NOT NULL, expediteur VARCHAR(255) NOT NULL, recu_le DATETIME DEFAULT NULL, site VARCHAR(255) NOT NULL, modele_imprimante VARCHAR(255) NOT NULL, numero_serie VARCHAR(100) NOT NULL, motif_alerte CLOB NOT NULL, etat VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL)');
            $this->addSql('CREATE INDEX idx_alerte_site ON alerte (site)');
            $this->addSql('CREATE INDEX idx_alerte_numero_serie ON alerte (numero_serie)');
            $this->addSql('CREATE INDEX idx_alerte_recu_le ON alerte (recu_le)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE alerte');
    }
}
