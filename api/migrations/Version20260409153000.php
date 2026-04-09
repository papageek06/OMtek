<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260409153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute une liaison forte alerte -> imprimante et retro-lie les alertes existantes par numero de serie.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('alerte')) {
            return;
        }

        $table = $schema->getTable('alerte');
        if ($table->hasColumn('imprimante_id')) {
            return;
        }

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE alerte ADD imprimante_id INT DEFAULT NULL');
            $this->addSql('CREATE INDEX idx_alerte_imprimante ON alerte (imprimante_id)');
            $this->addSql('ALTER TABLE alerte ADD CONSTRAINT FK_ALERTE_IMPRIMANTE FOREIGN KEY (imprimante_id) REFERENCES imprimante (id) ON DELETE SET NULL');
            $this->addSql('UPDATE alerte a INNER JOIN imprimante i ON i.numero_serie = a.numero_serie SET a.imprimante_id = i.id WHERE a.imprimante_id IS NULL');
            return;
        }

        if ($platform instanceof SqlitePlatform) {
            $this->addSql('ALTER TABLE alerte ADD COLUMN imprimante_id INTEGER DEFAULT NULL');
            $this->addSql('CREATE INDEX idx_alerte_imprimante ON alerte (imprimante_id)');
            $this->addSql('UPDATE alerte SET imprimante_id = (SELECT i.id FROM imprimante i WHERE i.numero_serie = alerte.numero_serie LIMIT 1) WHERE imprimante_id IS NULL');
            return;
        }

        $this->abortIf(true, 'Plateforme non supportee pour cette migration.');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('alerte')) {
            return;
        }

        $table = $schema->getTable('alerte');
        if (!$table->hasColumn('imprimante_id')) {
            return;
        }

        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE alerte DROP FOREIGN KEY FK_ALERTE_IMPRIMANTE');
            $this->addSql('DROP INDEX idx_alerte_imprimante ON alerte');
            $this->addSql('ALTER TABLE alerte DROP imprimante_id');
            return;
        }

        if (!($platform instanceof SqlitePlatform)) {
            $this->abortIf(true, 'Plateforme non supportee pour cette migration.');
        }

        $this->addSql('CREATE TABLE alerte_old (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, message_id VARCHAR(255) DEFAULT NULL, sujet VARCHAR(500) NOT NULL, expediteur VARCHAR(255) NOT NULL, recu_le DATETIME DEFAULT NULL, site VARCHAR(255) NOT NULL, modele_imprimante VARCHAR(255) NOT NULL, numero_serie VARCHAR(100) NOT NULL, motif_alerte CLOB NOT NULL, piece VARCHAR(255) NOT NULL, niveau_pourcent INT DEFAULT NULL, created_at DATETIME NOT NULL, ignorer BOOLEAN DEFAULT 0 NOT NULL)');
        $this->addSql('INSERT INTO alerte_old (id, message_id, sujet, expediteur, recu_le, site, modele_imprimante, numero_serie, motif_alerte, piece, niveau_pourcent, created_at, ignorer) SELECT id, message_id, sujet, expediteur, recu_le, site, modele_imprimante, numero_serie, motif_alerte, piece, niveau_pourcent, created_at, ignorer FROM alerte');
        $this->addSql('DROP TABLE alerte');
        $this->addSql('ALTER TABLE alerte_old RENAME TO alerte');
        $this->addSql('CREATE INDEX idx_alerte_site ON alerte (site)');
        $this->addSql('CREATE INDEX idx_alerte_numero_serie ON alerte (numero_serie)');
        $this->addSql('CREATE INDEX idx_alerte_recu_le ON alerte (recu_le)');
    }
}
