<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260313143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout des champs de valorisation HT des interventions (temps, pieces, deplacement, total).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE intervention
            ADD intervention_duration_minutes INT DEFAULT NULL,
            ADD intervention_labor_cost_ht NUMERIC(12, 2) DEFAULT NULL,
            ADD intervention_parts_cost_ht NUMERIC(12, 2) DEFAULT NULL,
            ADD intervention_travel_cost_ht NUMERIC(12, 2) DEFAULT NULL,
            ADD intervention_total_cost_ht NUMERIC(12, 2) DEFAULT NULL,
            ADD intervention_billing_notes LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE intervention
            DROP intervention_duration_minutes,
            DROP intervention_labor_cost_ht,
            DROP intervention_parts_cost_ht,
            DROP intervention_travel_cost_ht,
            DROP intervention_total_cost_ht,
            DROP intervention_billing_notes');
    }
}
