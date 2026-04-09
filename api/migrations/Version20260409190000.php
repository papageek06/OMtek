<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260409190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la table des evenements de remplacement toner (idempotence + compteurs).';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql(<<<'SQL'
CREATE TABLE toner_replacement_event (
    id INT AUTO_INCREMENT NOT NULL,
    imprimante_id INT NOT NULL,
    site_id INT DEFAULT NULL,
    piece_id INT DEFAULT NULL,
    stock_movement_id INT DEFAULT NULL,
    source_alerte_id INT DEFAULT NULL,
    source_rapport_id INT DEFAULT NULL,
    source_type VARCHAR(20) NOT NULL,
    event_key VARCHAR(191) NOT NULL,
    color_key VARCHAR(20) NOT NULL,
    detected_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    level_before INT DEFAULT NULL,
    level_after INT DEFAULT NULL,
    counter_value INT DEFAULT NULL,
    previous_counter_value INT DEFAULT NULL,
    copies_since_previous INT DEFAULT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX idx_toner_replacement_printer_color_detected (imprimante_id, color_key, detected_at),
    INDEX idx_toner_replacement_site_detected (site_id, detected_at),
    INDEX IDX_1A7E30CE8E0A4A (piece_id),
    INDEX IDX_1A7E30CEB2E6E11A (stock_movement_id),
    INDEX IDX_1A7E30CED89C4B4A (source_alerte_id),
    INDEX IDX_1A7E30CE2D5D1882 (source_rapport_id),
    UNIQUE INDEX uniq_toner_replacement_event_key (event_key),
    PRIMARY KEY(id),
    CONSTRAINT FK_1A7E30CEEA6B4EEB FOREIGN KEY (imprimante_id) REFERENCES imprimante (id) ON DELETE CASCADE,
    CONSTRAINT FK_1A7E30CEF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE SET NULL,
    CONSTRAINT FK_1A7E30CE8E0A4A FOREIGN KEY (piece_id) REFERENCES piece (id) ON DELETE SET NULL,
    CONSTRAINT FK_1A7E30CEB2E6E11A FOREIGN KEY (stock_movement_id) REFERENCES stock_movement (id) ON DELETE SET NULL,
    CONSTRAINT FK_1A7E30CED89C4B4A FOREIGN KEY (source_alerte_id) REFERENCES alerte (id) ON DELETE SET NULL,
    CONSTRAINT FK_1A7E30CE2D5D1882 FOREIGN KEY (source_rapport_id) REFERENCES rapport_imprimante (id) ON DELETE SET NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
            return;
        }

        if ($platform instanceof SqlitePlatform) {
            $this->addSql(<<<'SQL'
CREATE TABLE toner_replacement_event (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    imprimante_id INTEGER NOT NULL,
    site_id INTEGER DEFAULT NULL,
    piece_id INTEGER DEFAULT NULL,
    stock_movement_id INTEGER DEFAULT NULL,
    source_alerte_id INTEGER DEFAULT NULL,
    source_rapport_id INTEGER DEFAULT NULL,
    source_type VARCHAR(20) NOT NULL,
    event_key VARCHAR(191) NOT NULL,
    color_key VARCHAR(20) NOT NULL,
    detected_at DATETIME NOT NULL,
    level_before INTEGER DEFAULT NULL,
    level_after INTEGER DEFAULT NULL,
    counter_value INTEGER DEFAULT NULL,
    previous_counter_value INTEGER DEFAULT NULL,
    copies_since_previous INTEGER DEFAULT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT FK_1A7E30CEEA6B4EEB FOREIGN KEY (imprimante_id) REFERENCES imprimante (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
    CONSTRAINT FK_1A7E30CEF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
    CONSTRAINT FK_1A7E30CE8E0A4A FOREIGN KEY (piece_id) REFERENCES piece (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
    CONSTRAINT FK_1A7E30CEB2E6E11A FOREIGN KEY (stock_movement_id) REFERENCES stock_movement (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
    CONSTRAINT FK_1A7E30CED89C4B4A FOREIGN KEY (source_alerte_id) REFERENCES alerte (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
    CONSTRAINT FK_1A7E30CE2D5D1882 FOREIGN KEY (source_rapport_id) REFERENCES rapport_imprimante (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
)
SQL);
            $this->addSql('CREATE UNIQUE INDEX uniq_toner_replacement_event_key ON toner_replacement_event (event_key)');
            $this->addSql('CREATE INDEX idx_toner_replacement_printer_color_detected ON toner_replacement_event (imprimante_id, color_key, detected_at)');
            $this->addSql('CREATE INDEX idx_toner_replacement_site_detected ON toner_replacement_event (site_id, detected_at)');
            $this->addSql('CREATE INDEX IDX_1A7E30CE8E0A4A ON toner_replacement_event (piece_id)');
            $this->addSql('CREATE INDEX IDX_1A7E30CEB2E6E11A ON toner_replacement_event (stock_movement_id)');
            $this->addSql('CREATE INDEX IDX_1A7E30CED89C4B4A ON toner_replacement_event (source_alerte_id)');
            $this->addSql('CREATE INDEX IDX_1A7E30CE2D5D1882 ON toner_replacement_event (source_rapport_id)');
            return;
        }

        $this->abortIf(true, 'Plateforme non supportee pour cette migration.');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform || $platform instanceof SqlitePlatform) {
            $this->addSql('DROP TABLE toner_replacement_event');
            return;
        }

        $this->abortIf(true, 'Plateforme non supportee pour cette migration.');
    }
}
