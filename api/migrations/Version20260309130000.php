<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260309130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout interventions, mouvements de stock et scope de stock admin-only.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE stock ADD scope VARCHAR(20) NOT NULL DEFAULT 'TECH_VISIBLE'");
        $this->addSql('CREATE INDEX idx_stock_scope ON stock (scope)');
        $this->addSql('ALTER TABLE stock DROP INDEX uniq_stock_piece_site');
        $this->addSql('CREATE UNIQUE INDEX uniq_stock_piece_site_scope ON stock (piece_id, site_id, scope)');

        $this->addSql("CREATE TABLE intervention (
            id INT AUTO_INCREMENT NOT NULL,
            site_id INT NOT NULL,
            imprimante_id INT DEFAULT NULL,
            created_by_user_id INT NOT NULL,
            assigned_to_user_id INT DEFAULT NULL,
            source_alerte_id INT DEFAULT NULL,
            type VARCHAR(30) NOT NULL,
            source VARCHAR(30) NOT NULL,
            priorite VARCHAR(20) NOT NULL,
            statut VARCHAR(20) NOT NULL,
            billing_status VARCHAR(20) NOT NULL,
            title VARCHAR(160) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            notes_tech LONGTEXT DEFAULT NULL,
            started_at DATETIME DEFAULT NULL,
            closed_at DATETIME DEFAULT NULL,
            archived TINYINT(1) DEFAULT 0 NOT NULL,
            archived_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_intervention_site_statut (site_id, statut),
            INDEX idx_intervention_assigned_statut (assigned_to_user_id, statut),
            INDEX idx_intervention_billing_status (billing_status),
            INDEX IDX_D11814AB1CA0A76 (imprimante_id),
            INDEX IDX_D11814AB7D182D95 (created_by_user_id),
            INDEX IDX_D11814AB440535E7 (source_alerte_id),
            CONSTRAINT FK_intervention_site FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE CASCADE,
            CONSTRAINT FK_intervention_imprimante FOREIGN KEY (imprimante_id) REFERENCES imprimante (id) ON DELETE SET NULL,
            CONSTRAINT FK_intervention_created_by FOREIGN KEY (created_by_user_id) REFERENCES user (id) ON DELETE CASCADE,
            CONSTRAINT FK_intervention_assigned_to FOREIGN KEY (assigned_to_user_id) REFERENCES user (id) ON DELETE SET NULL,
            CONSTRAINT FK_intervention_source_alerte FOREIGN KEY (source_alerte_id) REFERENCES alerte (id) ON DELETE SET NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql("CREATE TABLE stock_movement (
            id INT AUTO_INCREMENT NOT NULL,
            stock_id INT DEFAULT NULL,
            piece_id INT NOT NULL,
            site_id INT DEFAULT NULL,
            user_id INT NOT NULL,
            intervention_id INT DEFAULT NULL,
            movement_type VARCHAR(20) NOT NULL,
            stock_scope VARCHAR(20) NOT NULL,
            quantity_delta INT NOT NULL,
            quantity_before INT NOT NULL,
            quantity_after INT NOT NULL,
            reason VARCHAR(50) NOT NULL,
            commentaire LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_stock_movement_site_created (site_id, created_at),
            INDEX idx_stock_movement_piece_created (piece_id, created_at),
            INDEX idx_stock_movement_intervention (intervention_id),
            INDEX IDX_BB1BC1B5DCD6110 (stock_id),
            INDEX IDX_BB1BC1B5A76ED395 (user_id),
            CONSTRAINT FK_stock_movement_stock FOREIGN KEY (stock_id) REFERENCES stock (id) ON DELETE SET NULL,
            CONSTRAINT FK_stock_movement_piece FOREIGN KEY (piece_id) REFERENCES piece (id) ON DELETE CASCADE,
            CONSTRAINT FK_stock_movement_site FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE SET NULL,
            CONSTRAINT FK_stock_movement_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE,
            CONSTRAINT FK_stock_movement_intervention FOREIGN KEY (intervention_id) REFERENCES intervention (id) ON DELETE SET NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE stock_movement');
        $this->addSql('DROP TABLE intervention');
        $this->addSql('ALTER TABLE stock DROP INDEX uniq_stock_piece_site_scope');
        $this->addSql('CREATE UNIQUE INDEX uniq_stock_piece_site ON stock (piece_id, site_id)');
        $this->addSql('DROP INDEX idx_stock_scope ON stock');
        $this->addSql('ALTER TABLE stock DROP COLUMN scope');
    }
}
