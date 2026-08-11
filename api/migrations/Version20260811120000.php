<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260811120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute l origine technique des alertes mail.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql("ALTER TABLE alerte ADD source VARCHAR(30) NOT NULL DEFAULT 'MAIL_FETCHER'");
            $this->addSql('CREATE INDEX idx_alerte_source ON alerte (source)');
            return;
        }

        if ($platform instanceof SqlitePlatform) {
            $this->addSql("ALTER TABLE alerte ADD COLUMN source VARCHAR(30) NOT NULL DEFAULT 'MAIL_FETCHER'");
            $this->addSql('CREATE INDEX idx_alerte_source ON alerte (source)');
            return;
        }

        $this->abortIf(true, 'Plateforme non supportee pour cette migration.');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('DROP INDEX idx_alerte_source ON alerte');
            $this->addSql('ALTER TABLE alerte DROP source');
            return;
        }

        if ($platform instanceof SqlitePlatform) {
            $this->addSql('DROP INDEX idx_alerte_source');
            $this->addSql('CREATE TABLE alerte_old AS SELECT id, message_id, sujet, expediteur, recu_le, site, modele_imprimante, numero_serie, imprimante_id, motif_alerte, piece, niveau_pourcent, created_at, ignorer FROM alerte');
            $this->addSql('DROP TABLE alerte');
            $this->addSql('ALTER TABLE alerte_old RENAME TO alerte');
            $this->addSql('CREATE INDEX idx_alerte_site ON alerte (site)');
            $this->addSql('CREATE INDEX idx_alerte_numero_serie ON alerte (numero_serie)');
            $this->addSql('CREATE INDEX idx_alerte_recu_le ON alerte (recu_le)');
            $this->addSql('CREATE INDEX idx_alerte_imprimante ON alerte (imprimante_id)');
            return;
        }

        $this->abortIf(true, 'Plateforme non supportee pour cette migration.');
    }
}
