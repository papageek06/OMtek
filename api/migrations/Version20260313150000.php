<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260313150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout tarif/indexation appliques au niveau ligne_facturation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ligne_facturation
            ADD tarif_unitaire_ht NUMERIC(12, 6) DEFAULT NULL,
            ADD coefficient_indexation NUMERIC(12, 6) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ligne_facturation
            DROP tarif_unitaire_ht,
            DROP coefficient_indexation');
    }
}
