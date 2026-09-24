<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le suivi des affectations imprimante/site et les anomalies d import CSV.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform && !$platform instanceof SqlitePlatform) {
            $this->abortIf(true, 'Plateforme non supportee pour cette migration.');
        }

        if (!$schema->hasTable('printer_assignment')) {
            if ($platform instanceof SqlitePlatform) {
                $this->addSql("CREATE TABLE printer_assignment (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    imprimante_id INTEGER NOT NULL,
                    site_id INTEGER DEFAULT NULL,
                    intervention_id INTEGER DEFAULT NULL,
                    status VARCHAR(30) NOT NULL,
                    started_at DATETIME NOT NULL,
                    ended_at DATETIME DEFAULT NULL,
                    source VARCHAR(40) NOT NULL,
                    notes CLOB DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    FOREIGN KEY(imprimante_id) REFERENCES imprimante (id) ON DELETE CASCADE,
                    FOREIGN KEY(site_id) REFERENCES site (id) ON DELETE SET NULL,
                    FOREIGN KEY(intervention_id) REFERENCES intervention (id) ON DELETE SET NULL
                )");
            } else {
                $this->addSql("CREATE TABLE printer_assignment (
                    id INT AUTO_INCREMENT NOT NULL,
                    imprimante_id INT NOT NULL,
                    site_id INT DEFAULT NULL,
                    intervention_id INT DEFAULT NULL,
                    status VARCHAR(30) NOT NULL,
                    started_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    ended_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                    source VARCHAR(40) NOT NULL,
                    notes LONGTEXT DEFAULT NULL,
                    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    INDEX idx_printer_assignment_printer_started (imprimante_id, started_at),
                    INDEX idx_printer_assignment_site_started (site_id, started_at),
                    INDEX idx_printer_assignment_ended (ended_at),
                    INDEX IDX_82F138B312263282 (intervention_id),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
                $this->addSql('ALTER TABLE printer_assignment ADD CONSTRAINT FK_82F138B31ACFAA8 FOREIGN KEY (imprimante_id) REFERENCES imprimante (id) ON DELETE CASCADE');
                $this->addSql('ALTER TABLE printer_assignment ADD CONSTRAINT FK_82F138B3F6BD1646 FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE SET NULL');
                $this->addSql('ALTER TABLE printer_assignment ADD CONSTRAINT FK_82F138B312263282 FOREIGN KEY (intervention_id) REFERENCES intervention (id) ON DELETE SET NULL');
            }

            if ($platform instanceof SqlitePlatform) {
                $this->addSql('CREATE INDEX idx_printer_assignment_printer_started ON printer_assignment (imprimante_id, started_at)');
                $this->addSql('CREATE INDEX idx_printer_assignment_site_started ON printer_assignment (site_id, started_at)');
                $this->addSql('CREATE INDEX idx_printer_assignment_ended ON printer_assignment (ended_at)');
                $this->addSql('CREATE INDEX IDX_82F138B312263282 ON printer_assignment (intervention_id)');
            }
        }

        if (!$schema->hasTable('csv_import_anomaly')) {
            if ($platform instanceof SqlitePlatform) {
                $this->addSql("CREATE TABLE csv_import_anomaly (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    imprimante_id INTEGER DEFAULT NULL,
                    current_site_id INTEGER DEFAULT NULL,
                    incoming_site_id INTEGER DEFAULT NULL,
                    type VARCHAR(60) NOT NULL,
                    serial_number VARCHAR(100) NOT NULL,
                    incoming_site_name VARCHAR(255) DEFAULT NULL,
                    payload CLOB DEFAULT NULL,
                    status VARCHAR(20) NOT NULL,
                    created_at DATETIME NOT NULL,
                    resolved_at DATETIME DEFAULT NULL,
                    FOREIGN KEY(imprimante_id) REFERENCES imprimante (id) ON DELETE SET NULL,
                    FOREIGN KEY(current_site_id) REFERENCES site (id) ON DELETE SET NULL,
                    FOREIGN KEY(incoming_site_id) REFERENCES site (id) ON DELETE SET NULL
                )");
            } else {
                $this->addSql("CREATE TABLE csv_import_anomaly (
                    id INT AUTO_INCREMENT NOT NULL,
                    imprimante_id INT DEFAULT NULL,
                    current_site_id INT DEFAULT NULL,
                    incoming_site_id INT DEFAULT NULL,
                    type VARCHAR(60) NOT NULL,
                    serial_number VARCHAR(100) NOT NULL,
                    incoming_site_name VARCHAR(255) DEFAULT NULL,
                    payload JSON DEFAULT NULL,
                    status VARCHAR(20) NOT NULL,
                    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    resolved_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                    INDEX idx_csv_anomaly_serial_status (serial_number, status),
                    INDEX idx_csv_anomaly_type_status (type, status),
                    INDEX idx_csv_anomaly_created (created_at),
                    INDEX IDX_2B1798361ACFAA8 (imprimante_id),
                    INDEX IDX_2B17983668F12B (current_site_id),
                    INDEX IDX_2B179836A0328466 (incoming_site_id),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
                $this->addSql('ALTER TABLE csv_import_anomaly ADD CONSTRAINT FK_2B1798361ACFAA8 FOREIGN KEY (imprimante_id) REFERENCES imprimante (id) ON DELETE SET NULL');
                $this->addSql('ALTER TABLE csv_import_anomaly ADD CONSTRAINT FK_2B17983668F12B FOREIGN KEY (current_site_id) REFERENCES site (id) ON DELETE SET NULL');
                $this->addSql('ALTER TABLE csv_import_anomaly ADD CONSTRAINT FK_2B179836A0328466 FOREIGN KEY (incoming_site_id) REFERENCES site (id) ON DELETE SET NULL');
            }

            if ($platform instanceof SqlitePlatform) {
                $this->addSql('CREATE INDEX idx_csv_anomaly_serial_status ON csv_import_anomaly (serial_number, status)');
                $this->addSql('CREATE INDEX idx_csv_anomaly_type_status ON csv_import_anomaly (type, status)');
                $this->addSql('CREATE INDEX idx_csv_anomaly_created ON csv_import_anomaly (created_at)');
                $this->addSql('CREATE INDEX IDX_2B1798361ACFAA8 ON csv_import_anomaly (imprimante_id)');
                $this->addSql('CREATE INDEX IDX_2B17983668F12B ON csv_import_anomaly (current_site_id)');
                $this->addSql('CREATE INDEX IDX_2B179836A0328466 ON csv_import_anomaly (incoming_site_id)');
            }
        }

        if ($schema->hasTable('rapport_imprimante')) {
            $table = $schema->getTable('rapport_imprimante');
            if (!$table->hasColumn('site_id_snapshot')) {
                $this->addSql('ALTER TABLE rapport_imprimante ADD site_id_snapshot INT DEFAULT NULL');
            }
            if (!$table->hasColumn('assignment_id')) {
                $this->addSql('ALTER TABLE rapport_imprimante ADD assignment_id INT DEFAULT NULL');
            }

            if ($platform instanceof SqlitePlatform) {
                $this->addSql('CREATE INDEX IF NOT EXISTS IDX_B6824F9B2A999A4C ON rapport_imprimante (site_id_snapshot)');
                $this->addSql('CREATE INDEX IF NOT EXISTS IDX_B6824F9BE17896AB ON rapport_imprimante (assignment_id)');
            } else {
                $this->addSql('CREATE INDEX IDX_B6824F9B2A999A4C ON rapport_imprimante (site_id_snapshot)');
                $this->addSql('CREATE INDEX IDX_B6824F9BE17896AB ON rapport_imprimante (assignment_id)');
                $this->addSql('ALTER TABLE rapport_imprimante ADD CONSTRAINT FK_B6824F9B2A999A4C FOREIGN KEY (site_id_snapshot) REFERENCES site (id) ON DELETE SET NULL');
                $this->addSql('ALTER TABLE rapport_imprimante ADD CONSTRAINT FK_B6824F9BE17896AB FOREIGN KEY (assignment_id) REFERENCES printer_assignment (id) ON DELETE SET NULL');
            }
        }

        $now = $platform instanceof SqlitePlatform ? "datetime('now')" : 'CURRENT_TIMESTAMP';
        $this->addSql("INSERT INTO printer_assignment (imprimante_id, site_id, status, started_at, ended_at, source, notes, created_at, updated_at)
            SELECT i.id, i.site_id,
                CASE WHEN i.gerer = 1 THEN 'EN_SERVICE' ELSE 'RETIREE' END,
                COALESCE(i.created_at, $now),
                CASE WHEN i.gerer = 1 THEN NULL ELSE i.updated_at END,
                'MIGRATION_BACKFILL',
                'Affectation initiale creee depuis imprimante.site_id',
                $now,
                $now
            FROM imprimante i
            WHERE i.site_id IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM printer_assignment pa WHERE pa.imprimante_id = i.id)");

        if ($schema->hasTable('rapport_imprimante')) {
            if ($platform instanceof SqlitePlatform) {
                $this->addSql('UPDATE rapport_imprimante SET site_id_snapshot = (SELECT site_id FROM imprimante WHERE imprimante.id = rapport_imprimante.imprimante_id) WHERE site_id_snapshot IS NULL');
            } else {
                $this->addSql('UPDATE rapport_imprimante r INNER JOIN imprimante i ON i.id = r.imprimante_id SET r.site_id_snapshot = i.site_id WHERE r.site_id_snapshot IS NULL');
            }
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform && !$platform instanceof SqlitePlatform) {
            $this->abortIf(true, 'Plateforme non supportee pour cette migration.');
        }

        if ($schema->hasTable('rapport_imprimante')) {
            if ($platform instanceof AbstractMySQLPlatform) {
                $this->addSql('ALTER TABLE rapport_imprimante DROP FOREIGN KEY FK_B6824F9B2A999A4C');
                $this->addSql('ALTER TABLE rapport_imprimante DROP FOREIGN KEY FK_B6824F9BE17896AB');
                $this->addSql('DROP INDEX IDX_B6824F9B2A999A4C ON rapport_imprimante');
                $this->addSql('DROP INDEX IDX_B6824F9BE17896AB ON rapport_imprimante');
            }
            if ($schema->getTable('rapport_imprimante')->hasColumn('site_id_snapshot')) {
                $this->addSql('ALTER TABLE rapport_imprimante DROP site_id_snapshot');
            }
            if ($schema->getTable('rapport_imprimante')->hasColumn('assignment_id')) {
                $this->addSql('ALTER TABLE rapport_imprimante DROP assignment_id');
            }
        }

        if ($schema->hasTable('csv_import_anomaly')) {
            $this->addSql('DROP TABLE csv_import_anomaly');
        }
        if ($schema->hasTable('printer_assignment')) {
            $this->addSql('DROP TABLE printer_assignment');
        }
    }
}
