<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identifie les imprimantes par serie et site et ajoute une empreinte unique aux nouveaux rapports CSV, sans modifier les donnees historiques.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(!($platform instanceof AbstractMySQLPlatform) && !($platform instanceof SqlitePlatform), 'Plateforme non supportee.');

        // Creer les nouveaux index avant de relacher l'unicite historique.
        $this->addSql('CREATE UNIQUE INDEX uniq_imprimante_serie_site ON imprimante (numero_serie, site_id)');
        $this->addSql('CREATE INDEX idx_imprimante_numero_serie ON imprimante (numero_serie)');
        $this->addSql($platform instanceof AbstractMySQLPlatform
            ? 'DROP INDEX uniq_imprimante_numero_serie ON imprimante'
            : 'DROP INDEX uniq_imprimante_numero_serie');
        $this->addSql('ALTER TABLE rapport_imprimante ADD COLUMN import_fingerprint VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_rapport_import_fingerprint ON rapport_imprimante (import_fingerprint)');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(!($platform instanceof AbstractMySQLPlatform) && !($platform instanceof SqlitePlatform), 'Plateforme non supportee.');
        $duplicate = $this->connection->fetchOne('SELECT numero_serie FROM imprimante GROUP BY numero_serie HAVING COUNT(*) > 1 LIMIT 1');
        $this->abortIf($duplicate !== false, 'Retour arriere impossible : plusieurs fiches partagent une serie. Aucune fiche ne sera fusionnee ou supprimee.');
        $hasFingerprint = $this->connection->fetchOne('SELECT id FROM rapport_imprimante WHERE import_fingerprint IS NOT NULL LIMIT 1');
        $this->abortIf($hasFingerprint !== false, 'Retour arriere impossible : des empreintes de rapports sont utilisees. Leur suppression ferait perdre la protection contre les doublons.');

        $this->addSql('CREATE UNIQUE INDEX uniq_imprimante_numero_serie ON imprimante (numero_serie)');
        foreach (['uniq_imprimante_serie_site' => 'imprimante', 'idx_imprimante_numero_serie' => 'imprimante', 'uniq_rapport_import_fingerprint' => 'rapport_imprimante'] as $index => $table) {
            $this->addSql('DROP INDEX ' . $index . ($platform instanceof AbstractMySQLPlatform ? ' ON ' . $table : ''));
        }
        $this->addSql('ALTER TABLE rapport_imprimante DROP COLUMN import_fingerprint');
    }
}
