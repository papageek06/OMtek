<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260313160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suppression du champ legacy forfait_maintenance sur contrat (tarification via lignes).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contrat DROP forfait_maintenance');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE contrat ADD forfait_maintenance NUMERIC(10, 2) DEFAULT '0.00' NOT NULL");
    }
}

