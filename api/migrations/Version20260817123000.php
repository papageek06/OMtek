<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260817123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les emails, telephones et adresses multiples aux contacts importes.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            foreach ([
                'email_addresses',
                'phone_numbers',
                'business_address',
                'home_address',
                'other_address',
            ] as $column) {
                if (!$schema->getTable('contact')->hasColumn($column)) {
                    $this->addSql(sprintf('ALTER TABLE contact ADD %s JSON DEFAULT NULL', $column));
                }
            }
            return;
        }

        if ($platform instanceof SqlitePlatform) {
            return;
        }

        $this->abortIf(true, 'Plateforme non supportee pour cette migration.');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            foreach ([
                'email_addresses',
                'phone_numbers',
                'business_address',
                'home_address',
                'other_address',
            ] as $column) {
                if ($schema->getTable('contact')->hasColumn($column)) {
                    $this->addSql(sprintf('ALTER TABLE contact DROP %s', $column));
                }
            }
            return;
        }

        if ($platform instanceof SqlitePlatform) {
            return;
        }

        $this->abortIf(true, 'Plateforme non supportee pour cette migration.');
    }
}
