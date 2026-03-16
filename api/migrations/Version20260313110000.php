<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260313110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 1 contrats: ajout lignes de contrat et candidats de remplacement imprimante.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE ligne_contrat (
            id INT AUTO_INCREMENT NOT NULL,
            contrat_id INT NOT NULL,
            site_id INT DEFAULT NULL,
            imprimante_id INT DEFAULT NULL,
            type VARCHAR(30) NOT NULL,
            libelle VARCHAR(255) NOT NULL,
            quantite NUMERIC(12, 3) DEFAULT 1.000 NOT NULL,
            prix_unitaire_ht NUMERIC(12, 6) DEFAULT 0.000000 NOT NULL,
            coefficient_indexation NUMERIC(12, 6) DEFAULT NULL,
            date_debut DATE DEFAULT NULL,
            date_fin DATE DEFAULT NULL,
            actif TINYINT(1) DEFAULT 1 NOT NULL,
            meta JSON DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_ligne_contrat_contrat_type (contrat_id, type),
            INDEX idx_ligne_contrat_site (site_id),
            INDEX idx_ligne_contrat_imprimante (imprimante_id),
            INDEX idx_ligne_contrat_actif_dates (actif, date_debut, date_fin),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE ligne_contrat ADD CONSTRAINT FK_ligne_contrat_contrat FOREIGN KEY (contrat_id) REFERENCES contrat (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ligne_contrat ADD CONSTRAINT FK_ligne_contrat_site FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ligne_contrat ADD CONSTRAINT FK_ligne_contrat_imprimante FOREIGN KEY (imprimante_id) REFERENCES imprimante (id) ON DELETE SET NULL');

        $this->addSql("CREATE TABLE printer_replacement_candidate (
            id INT AUTO_INCREMENT NOT NULL,
            site_id INT DEFAULT NULL,
            previous_printer_id INT DEFAULT NULL,
            candidate_printer_id INT DEFAULT NULL,
            confirmed_by_user_id INT DEFAULT NULL,
            shared_ip_address VARCHAR(45) DEFAULT NULL,
            trigger_type VARCHAR(50) NOT NULL,
            statut VARCHAR(20) NOT NULL,
            detected_at DATETIME NOT NULL,
            resolved_at DATETIME DEFAULT NULL,
            notes LONGTEXT DEFAULT NULL,
            meta JSON DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_replacement_candidate_site_status (site_id, statut),
            INDEX idx_replacement_candidate_previous (previous_printer_id),
            INDEX idx_replacement_candidate_candidate (candidate_printer_id),
            INDEX idx_replacement_candidate_detected (detected_at),
            INDEX idx_replacement_candidate_confirmed_by (confirmed_by_user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE printer_replacement_candidate ADD CONSTRAINT FK_replacement_candidate_site FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE printer_replacement_candidate ADD CONSTRAINT FK_replacement_candidate_previous FOREIGN KEY (previous_printer_id) REFERENCES imprimante (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE printer_replacement_candidate ADD CONSTRAINT FK_replacement_candidate_candidate FOREIGN KEY (candidate_printer_id) REFERENCES imprimante (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE printer_replacement_candidate ADD CONSTRAINT FK_replacement_candidate_confirmed_by FOREIGN KEY (confirmed_by_user_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE printer_replacement_candidate');
        $this->addSql('DROP TABLE ligne_contrat');
    }
}
