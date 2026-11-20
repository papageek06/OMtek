<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Exception\AbortMigration;
use DoctrineMigrations\Version20261103120000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../../migrations/Version20261103120000.php';

final class ManualPrinterMigrationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
        $this->connection->executeStatement('CREATE TABLE site (id INTEGER PRIMARY KEY, nom VARCHAR(255) NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE imprimante (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, site_id INTEGER DEFAULT NULL, numero_serie VARCHAR(100) NOT NULL, modele VARCHAR(255) NOT NULL, FOREIGN KEY(site_id) REFERENCES site (id) ON DELETE SET NULL)');
        $this->connection->executeStatement('CREATE UNIQUE INDEX uniq_imprimante_numero_serie ON imprimante (numero_serie)');
        $this->connection->executeStatement('CREATE INDEX idx_imprimante_site ON imprimante (site_id)');
        $this->connection->executeStatement('CREATE TABLE rapport_imprimante (id INTEGER PRIMARY KEY, imprimante_id INTEGER NOT NULL, FOREIGN KEY(imprimante_id) REFERENCES imprimante (id) ON DELETE CASCADE)');
        $this->connection->insert('site', ['id' => 1, 'nom' => 'Client']);
        $this->connection->insert('imprimante', ['id' => 7, 'site_id' => 1, 'numero_serie' => 'EXISTING', 'modele' => 'Legacy']);
        $this->connection->insert('rapport_imprimante', ['id' => 3, 'imprimante_id' => 7]);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testMigrationPreservesExistingDataIndexesAndReferencesAndAllowsMissingSeries(): void
    {
        $this->migrate('up');
        self::assertSame('EXISTING', $this->connection->fetchOne('SELECT numero_serie FROM imprimante WHERE id = 7'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT is_manual FROM imprimante WHERE id = 7'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM rapport_imprimante'));
        self::assertSame([], $this->connection->fetchAllAssociative('PRAGMA foreign_key_check'));
        self::assertTrue($this->connection->createSchemaManager()->introspectTable('imprimante')->hasIndex('uniq_imprimante_numero_serie'));
        foreach (['Accueil', 'Bureau'] as $model) {
            $this->connection->insert('imprimante', ['site_id' => 1, 'numero_serie' => null, 'modele' => $model, 'is_manual' => 1]);
        }
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM imprimante WHERE numero_serie IS NULL'));
        $this->expectException(AbortMigration::class);
        $this->migrate('down');
    }

    public function testRollbackRestoresSchemaWhenAllSeriesAreAvailable(): void
    {
        $before = $this->connection->createSchemaManager()->introspectSchema();
        $this->migrate('up');
        $this->migrate('down');
        $after = $this->connection->createSchemaManager()->introspectSchema();
        $diff = $this->connection->createSchemaManager()->createComparator()->compareSchemas($before, $after);
        self::assertTrue($diff->isEmpty());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM rapport_imprimante'));
    }

    private function migrate(string $direction): void
    {
        $migration = new Version20261103120000($this->connection, new NullLogger());
        $migration->$direction($this->connection->createSchemaManager()->introspectSchema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
