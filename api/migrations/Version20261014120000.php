<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Anomalies d'import CSV: dedoublonnage (fingerprint + occurrences), message lisible
 * et tracabilite du traitement admin (action, note, auteur).
 * Retrocompatible: colonnes nullables ou avec valeur par defaut, lignes existantes conservees.
 */
final class Version20261014120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le dedoublonnage et le suivi de traitement des anomalies d import CSV.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform && !$platform instanceof SqlitePlatform) {
            $this->abortIf(true, 'Plateforme non supportee pour cette migration.');
        }

        $isSqlite = $platform instanceof SqlitePlatform;
        $datetime = $isSqlite ? 'DATETIME' : "DATETIME COMMENT '(DC2Type:datetime_immutable)'";
        $text = $isSqlite ? 'CLOB' : 'LONGTEXT';

        $this->addSql('ALTER TABLE csv_import_anomaly ADD fingerprint VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE csv_import_anomaly ADD message VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE csv_import_anomaly ADD occurrences INT DEFAULT 1 NOT NULL');
        $this->addSql("ALTER TABLE csv_import_anomaly ADD last_seen_at $datetime DEFAULT NULL");
        $this->addSql('ALTER TABLE csv_import_anomaly ADD resolution_action VARCHAR(40) DEFAULT NULL');
        $this->addSql("ALTER TABLE csv_import_anomaly ADD resolution_note $text DEFAULT NULL");
        $this->addSql('ALTER TABLE csv_import_anomaly ADD resolved_by_id INT DEFAULT NULL');

        $this->addSql('UPDATE csv_import_anomaly SET last_seen_at = created_at WHERE last_seen_at IS NULL');

        $this->addSql('CREATE INDEX idx_csv_anomaly_fingerprint_status ON csv_import_anomaly (fingerprint, status)');
        $this->addSql('CREATE INDEX IDX_2B179836F2B6E9BE ON csv_import_anomaly (resolved_by_id)');
        if (!$isSqlite) {
            $this->addSql('ALTER TABLE csv_import_anomaly ADD CONSTRAINT FK_2B179836F2B6E9BE FOREIGN KEY (resolved_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform && !$platform instanceof SqlitePlatform) {
            $this->abortIf(true, 'Plateforme non supportee pour cette migration.');
        }

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE csv_import_anomaly DROP FOREIGN KEY FK_2B179836F2B6E9BE');
        }
        $this->addSql('DROP INDEX IDX_2B179836F2B6E9BE' . ($platform instanceof AbstractMySQLPlatform ? ' ON csv_import_anomaly' : ''));
        $this->addSql('DROP INDEX idx_csv_anomaly_fingerprint_status' . ($platform instanceof AbstractMySQLPlatform ? ' ON csv_import_anomaly' : ''));

        foreach (['resolved_by_id', 'resolution_note', 'resolution_action', 'last_seen_at', 'occurrences', 'message', 'fingerprint'] as $column) {
            $this->addSql(sprintf('ALTER TABLE csv_import_anomaly DROP %s', $column));
        }
    }
}
