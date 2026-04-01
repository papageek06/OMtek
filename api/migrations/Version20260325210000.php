<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout des donnees terrain par site: NOTscan, identifiants, notes et fichiers.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE site_notscan (
            id INT AUTO_INCREMENT NOT NULL,
            site_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            address VARCHAR(255) DEFAULT NULL,
            notes LONGTEXT DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1 NOT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX idx_site_notscan_site (site_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE site_notscan ADD CONSTRAINT FK_SITE_NOTSCAN_SITE FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE CASCADE');

        $this->addSql("CREATE TABLE site_credential (
            id INT AUTO_INCREMENT NOT NULL,
            site_id INT NOT NULL,
            notscan_id INT DEFAULT NULL,
            label VARCHAR(255) NOT NULL,
            username VARCHAR(255) DEFAULT NULL,
            secret_encrypted LONGTEXT NOT NULL,
            notes LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX idx_site_credential_site (site_id),
            INDEX idx_site_credential_notscan (notscan_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE site_credential ADD CONSTRAINT FK_SITE_CREDENTIAL_SITE FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE site_credential ADD CONSTRAINT FK_SITE_CREDENTIAL_NOTSCAN FOREIGN KEY (notscan_id) REFERENCES site_notscan (id) ON DELETE SET NULL');

        $this->addSql("CREATE TABLE site_note (
            id INT AUTO_INCREMENT NOT NULL,
            site_id INT NOT NULL,
            content LONGTEXT NOT NULL,
            author_name VARCHAR(120) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            INDEX idx_site_note_site (site_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE site_note ADD CONSTRAINT FK_SITE_NOTE_SITE FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE CASCADE');

        $this->addSql("CREATE TABLE site_file (
            id INT AUTO_INCREMENT NOT NULL,
            site_id INT NOT NULL,
            label VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            relative_path VARCHAR(500) NOT NULL,
            mime_type VARCHAR(120) DEFAULT NULL,
            extension VARCHAR(20) DEFAULT NULL,
            size_bytes INT DEFAULT 0 NOT NULL,
            category VARCHAR(30) DEFAULT 'OTHER' NOT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX uniq_site_file_relative_path (relative_path),
            INDEX idx_site_file_site (site_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE site_file ADD CONSTRAINT FK_SITE_FILE_SITE FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_credential DROP FOREIGN KEY FK_SITE_CREDENTIAL_NOTSCAN');
        $this->addSql('ALTER TABLE site_file DROP FOREIGN KEY FK_SITE_FILE_SITE');
        $this->addSql('ALTER TABLE site_note DROP FOREIGN KEY FK_SITE_NOTE_SITE');
        $this->addSql('ALTER TABLE site_credential DROP FOREIGN KEY FK_SITE_CREDENTIAL_SITE');
        $this->addSql('ALTER TABLE site_notscan DROP FOREIGN KEY FK_SITE_NOTSCAN_SITE');
        $this->addSql('DROP TABLE site_file');
        $this->addSql('DROP TABLE site_note');
        $this->addSql('DROP TABLE site_credential');
        $this->addSql('DROP TABLE site_notscan');
    }
}
