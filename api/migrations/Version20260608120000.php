<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260608120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les contacts synchronisables Exchange et leur liaison aux sites.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql(<<<'SQL'
CREATE TABLE contact (
    id INT AUTO_INCREMENT NOT NULL,
    exchange_id VARCHAR(255) DEFAULT NULL,
    exchange_change_key VARCHAR(255) DEFAULT NULL,
    display_name VARCHAR(180) NOT NULL,
    first_name VARCHAR(100) DEFAULT NULL,
    last_name VARCHAR(100) DEFAULT NULL,
    email VARCHAR(180) DEFAULT NULL,
    mobile_phone VARCHAR(60) DEFAULT NULL,
    business_phone VARCHAR(60) DEFAULT NULL,
    company_name VARCHAR(180) DEFAULT NULL,
    job_title VARCHAR(180) DEFAULT NULL,
    notes LONGTEXT DEFAULT NULL,
    synced_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    UNIQUE INDEX uniq_contact_exchange_id (exchange_id),
    INDEX idx_contact_display_name (display_name),
    INDEX idx_contact_email (email),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
            $this->addSql(<<<'SQL'
CREATE TABLE site_contact (
    id INT AUTO_INCREMENT NOT NULL,
    site_id INT NOT NULL,
    contact_id INT NOT NULL,
    role VARCHAR(80) DEFAULT NULL,
    is_favorite TINYINT(1) DEFAULT 0 NOT NULL,
    notes LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX idx_site_contact_site (site_id),
    INDEX idx_site_contact_contact (contact_id),
    INDEX idx_site_contact_favorite (is_favorite),
    UNIQUE INDEX uniq_site_contact_site_contact (site_id, contact_id),
    PRIMARY KEY(id),
    CONSTRAINT FK_SITE_CONTACT_SITE FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE CASCADE,
    CONSTRAINT FK_SITE_CONTACT_CONTACT FOREIGN KEY (contact_id) REFERENCES contact (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);
            return;
        }

        if ($platform instanceof SqlitePlatform) {
            $this->addSql(<<<'SQL'
CREATE TABLE contact (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    exchange_id VARCHAR(255) DEFAULT NULL,
    exchange_change_key VARCHAR(255) DEFAULT NULL,
    display_name VARCHAR(180) NOT NULL,
    first_name VARCHAR(100) DEFAULT NULL,
    last_name VARCHAR(100) DEFAULT NULL,
    email VARCHAR(180) DEFAULT NULL,
    mobile_phone VARCHAR(60) DEFAULT NULL,
    business_phone VARCHAR(60) DEFAULT NULL,
    company_name VARCHAR(180) DEFAULT NULL,
    job_title VARCHAR(180) DEFAULT NULL,
    notes CLOB DEFAULT NULL,
    synced_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
)
SQL);
            $this->addSql('CREATE UNIQUE INDEX uniq_contact_exchange_id ON contact (exchange_id)');
            $this->addSql('CREATE INDEX idx_contact_display_name ON contact (display_name)');
            $this->addSql('CREATE INDEX idx_contact_email ON contact (email)');
            $this->addSql(<<<'SQL'
CREATE TABLE site_contact (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    site_id INTEGER NOT NULL,
    contact_id INTEGER NOT NULL,
    role VARCHAR(80) DEFAULT NULL,
    is_favorite BOOLEAN DEFAULT 0 NOT NULL,
    notes CLOB DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT FK_SITE_CONTACT_SITE FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
    CONSTRAINT FK_SITE_CONTACT_CONTACT FOREIGN KEY (contact_id) REFERENCES contact (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
)
SQL);
            $this->addSql('CREATE INDEX idx_site_contact_site ON site_contact (site_id)');
            $this->addSql('CREATE INDEX idx_site_contact_contact ON site_contact (contact_id)');
            $this->addSql('CREATE INDEX idx_site_contact_favorite ON site_contact (is_favorite)');
            $this->addSql('CREATE UNIQUE INDEX uniq_site_contact_site_contact ON site_contact (site_id, contact_id)');
            return;
        }

        $this->abortIf(true, 'Plateforme non supportee pour cette migration.');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform || $platform instanceof SqlitePlatform) {
            $this->addSql('DROP TABLE site_contact');
            $this->addSql('DROP TABLE contact');
            return;
        }

        $this->abortIf(true, 'Plateforme non supportee pour cette migration.');
    }
}
