<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260223300000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création table user (authentification).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE `user` (
            id INT AUTO_INCREMENT NOT NULL,
            email VARCHAR(180) NOT NULL,
            password VARCHAR(255) NOT NULL,
            roles JSON NOT NULL,
            first_name VARCHAR(80) NOT NULL,
            last_name VARCHAR(80) NOT NULL,
            api_token VARCHAR(64) DEFAULT NULL,
            api_token_expires_at DATETIME DEFAULT NULL,
            email_verified TINYINT(1) DEFAULT 0 NOT NULL,
            email_verification_token VARCHAR(64) DEFAULT NULL,
            email_verification_token_expires_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_8D93D649E7927C74 (email),
            UNIQUE INDEX UNIQ_8D93D6497BA2F5EB (api_token),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE `user`');
    }
}
