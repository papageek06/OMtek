<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Inventaire manuel hors connexion : serie facultative et indicateur manuel (false pour les machines existantes).';
    }

    public function up(Schema $schema): void
    {
        $this->alterPrinter($schema, false);
    }

    public function isTransactional(): bool
    {
        // PRAGMA foreign_keys ne peut pas changer dans une transaction SQLite.
        return !$this->connection->getDatabasePlatform() instanceof SqlitePlatform;
    }

    public function down(Schema $schema): void
    {
        // Le retour arriere ne doit ni inventer des series ni supprimer des machines.
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM imprimante WHERE numero_serie IS NULL') > 0,
            'Renseigner les numeros de serie manquants avant le retour arriere.');
        $this->alterPrinter($schema, true);
    }

    private function alterPrinter(Schema $schema, bool $rollback): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(!$platform instanceof AbstractMySQLPlatform && !$platform instanceof SqlitePlatform, 'Plateforme non supportee.');
        $before = $schema->getTable('imprimante');
        $after = clone $before;
        $after->getColumn('numero_serie')->setNotnull($rollback);
        if ($rollback) {
            $after->dropColumn('is_manual');
        } else {
            $after->addColumn('is_manual', Types::BOOLEAN, ['default' => false, 'notnull' => true]);
        }
        // DBAL preserve colonnes, index et relations, y compris la reconstruction SQLite.
        $diff = $this->connection->createSchemaManager()->createComparator()->compareTables($before, $after);
        if ($platform instanceof SqlitePlatform) {
            $this->addSql('PRAGMA foreign_keys = OFF');
        }
        foreach ($platform->getAlterTableSQL($diff) as $sql) {
            $this->addSql($sql);
        }
        if ($platform instanceof SqlitePlatform) {
            $this->addSql('PRAGMA foreign_keys = ON');
        }
    }
}
