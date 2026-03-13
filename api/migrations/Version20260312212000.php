<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260312212000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Alignement Doctrine: suppression index redondant contrat_tarif.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_contrat_tarif_contrat_date ON contrat_tarif');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_contrat_tarif_contrat_date ON contrat_tarif (contrat_id, date_effet)');
    }
}

