<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260312211000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 1.2: socle contrat (contrat, tarifs, indexation, periodes et lignes de facturation).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE contrat (
            id INT AUTO_INCREMENT NOT NULL,
            site_id INT NOT NULL,
            reference VARCHAR(60) NOT NULL,
            libelle VARCHAR(160) NOT NULL,
            periodicite VARCHAR(20) NOT NULL,
            statut VARCHAR(20) NOT NULL,
            date_debut DATE NOT NULL,
            date_fin DATE DEFAULT NULL,
            forfait_maintenance NUMERIC(10, 2) DEFAULT 0 NOT NULL,
            devise VARCHAR(3) DEFAULT 'EUR' NOT NULL,
            notes LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_contrat_reference (reference),
            INDEX idx_contrat_site (site_id),
            INDEX idx_contrat_statut (statut),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE contrat ADD CONSTRAINT FK_contrat_site FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE CASCADE');

        $this->addSql("CREATE TABLE contrat_tarif (
            id INT AUTO_INCREMENT NOT NULL,
            contrat_id INT NOT NULL,
            date_effet DATE NOT NULL,
            prix_page_noir NUMERIC(12, 6) DEFAULT 0 NOT NULL,
            prix_page_couleur NUMERIC(12, 6) DEFAULT 0 NOT NULL,
            coefficient_indexation NUMERIC(12, 6) DEFAULT 1 NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_contrat_tarif_contrat_date_effet (contrat_id, date_effet),
            INDEX idx_contrat_tarif_contrat_date (contrat_id, date_effet),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE contrat_tarif ADD CONSTRAINT FK_contrat_tarif_contrat FOREIGN KEY (contrat_id) REFERENCES contrat (id) ON DELETE CASCADE');

        $this->addSql("CREATE TABLE contrat_indexation (
            id INT AUTO_INCREMENT NOT NULL,
            contrat_id INT NOT NULL,
            date_effet DATE NOT NULL,
            type VARCHAR(30) NOT NULL,
            valeur NUMERIC(12, 6) NOT NULL,
            commentaire LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_contrat_indexation_contrat_date (contrat_id, date_effet),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE contrat_indexation ADD CONSTRAINT FK_contrat_indexation_contrat FOREIGN KEY (contrat_id) REFERENCES contrat (id) ON DELETE CASCADE');

        $this->addSql("CREATE TABLE periode_facturation (
            id INT AUTO_INCREMENT NOT NULL,
            contrat_id INT NOT NULL,
            date_debut DATE NOT NULL,
            date_fin DATE NOT NULL,
            statut VARCHAR(20) NOT NULL,
            total_ht NUMERIC(12, 2) DEFAULT 0 NOT NULL,
            generated_at DATETIME NOT NULL,
            locked_at DATETIME DEFAULT NULL,
            UNIQUE INDEX uniq_periode_facturation_contrat_intervalle (contrat_id, date_debut, date_fin),
            INDEX idx_periode_facturation_contrat_statut (contrat_id, statut),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE periode_facturation ADD CONSTRAINT FK_periode_facturation_contrat FOREIGN KEY (contrat_id) REFERENCES contrat (id) ON DELETE CASCADE');

        $this->addSql("CREATE TABLE ligne_facturation (
            id INT AUTO_INCREMENT NOT NULL,
            periode_facturation_id INT NOT NULL,
            intervention_id INT DEFAULT NULL,
            imprimante_id INT DEFAULT NULL,
            type VARCHAR(30) NOT NULL,
            description VARCHAR(255) NOT NULL,
            quantite NUMERIC(12, 3) DEFAULT 0 NOT NULL,
            prix_unitaire_ht NUMERIC(12, 6) DEFAULT 0 NOT NULL,
            montant_ht NUMERIC(12, 2) DEFAULT 0 NOT NULL,
            meta JSON DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_ligne_facturation_periode_type (periode_facturation_id, type),
            INDEX idx_ligne_facturation_intervention (intervention_id),
            INDEX IDX_ligne_facturation_imprimante (imprimante_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE ligne_facturation ADD CONSTRAINT FK_ligne_facturation_periode FOREIGN KEY (periode_facturation_id) REFERENCES periode_facturation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ligne_facturation ADD CONSTRAINT FK_ligne_facturation_intervention FOREIGN KEY (intervention_id) REFERENCES intervention (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ligne_facturation ADD CONSTRAINT FK_ligne_facturation_imprimante FOREIGN KEY (imprimante_id) REFERENCES imprimante (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ligne_facturation');
        $this->addSql('DROP TABLE periode_facturation');
        $this->addSql('DROP TABLE contrat_indexation');
        $this->addSql('DROP TABLE contrat_tarif');
        $this->addSql('DROP TABLE contrat');
    }
}

