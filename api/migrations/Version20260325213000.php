<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Simplifie NOTscan (adresse + note) et retire la liaison des identifiants vers NOTscan.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE site_notscan SET address = \'\' WHERE address IS NULL');
        $this->addSql('ALTER TABLE site_notscan CHANGE address address VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE site_notscan DROP name');

        $this->addSql('ALTER TABLE site_credential DROP FOREIGN KEY FK_SITE_CREDENTIAL_NOTSCAN');
        $this->addSql('DROP INDEX idx_site_credential_notscan ON site_credential');
        $this->addSql('ALTER TABLE site_credential DROP notscan_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE site_notscan ADD name VARCHAR(255) NOT NULL DEFAULT 'NOTscan'");
        $this->addSql('ALTER TABLE site_notscan CHANGE address address VARCHAR(255) DEFAULT NULL');

        $this->addSql('ALTER TABLE site_credential ADD notscan_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_site_credential_notscan ON site_credential (notscan_id)');
        $this->addSql('ALTER TABLE site_credential ADD CONSTRAINT FK_SITE_CREDENTIAL_NOTSCAN FOREIGN KEY (notscan_id) REFERENCES site_notscan (id) ON DELETE SET NULL');
    }
}
