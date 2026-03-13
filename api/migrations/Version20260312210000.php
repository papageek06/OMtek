<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260312210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 1.1: validation admin des interventions (approval workflow).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE intervention ADD approval_status VARCHAR(20) NOT NULL DEFAULT 'DRAFT', ADD submitted_at DATETIME DEFAULT NULL, ADD approved_at DATETIME DEFAULT NULL, ADD approved_by_user_id INT DEFAULT NULL, ADD approval_note LONGTEXT DEFAULT NULL");
        $this->addSql('CREATE INDEX idx_intervention_approval_status ON intervention (approval_status)');
        $this->addSql('CREATE INDEX idx_intervention_approved_by ON intervention (approved_by_user_id)');
        $this->addSql('ALTER TABLE intervention ADD CONSTRAINT FK_intervention_approved_by FOREIGN KEY (approved_by_user_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE intervention DROP FOREIGN KEY FK_intervention_approved_by');
        $this->addSql('DROP INDEX idx_intervention_approved_by ON intervention');
        $this->addSql('DROP INDEX idx_intervention_approval_status ON intervention');
        $this->addSql('ALTER TABLE intervention DROP COLUMN approval_status, DROP COLUMN submitted_at, DROP COLUMN approved_at, DROP COLUMN approved_by_user_id, DROP COLUMN approval_note');
    }
}

