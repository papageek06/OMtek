<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325185000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Synchronise la valeur par defaut trigger_type sur printer_replacement_candidate.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE printer_replacement_candidate CHANGE trigger_type trigger_type VARCHAR(50) DEFAULT 'SAME_IP_NO_REPORT' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE printer_replacement_candidate CHANGE trigger_type trigger_type VARCHAR(50) NOT NULL");
    }
}

