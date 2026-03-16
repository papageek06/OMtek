<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260313153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suppression des tables legacy contrat_tarif et contrat_indexation (tarif/indexation portes par lignes).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE contrat_indexation');
        $this->addSql('DROP TABLE contrat_tarif');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("CREATE TABLE contrat_tarif (
            id INT AUTO_INCREMENT NOT NULL,
            contrat_id INT NOT NULL,
            date_effet DATE NOT NULL,
            prix_page_noir NUMERIC(12, 6) DEFAULT 0.000000 NOT NULL,
            prix_page_couleur NUMERIC(12, 6) DEFAULT 0.000000 NOT NULL,
            coefficient_indexation NUMERIC(12, 6) DEFAULT 1.000000 NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_contrat_tarif_contrat_date_effet (contrat_id, date_effet),
            INDEX IDX_CONTRAT_TARIF_CONTRAT (contrat_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE contrat_tarif ADD CONSTRAINT FK_CONTRAT_TARIF_CONTRAT FOREIGN KEY (contrat_id) REFERENCES contrat (id) ON DELETE CASCADE');

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
        $this->addSql('ALTER TABLE contrat_indexation ADD CONSTRAINT FK_CONTRAT_INDEXATION_CONTRAT FOREIGN KEY (contrat_id) REFERENCES contrat (id) ON DELETE CASCADE');
    }
}
