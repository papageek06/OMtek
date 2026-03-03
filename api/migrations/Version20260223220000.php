<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260223220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Alerte: remplacer etat par piece, ajouter niveau_pourcent.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof MySQLPlatform) {
            $this->addSql('ALTER TABLE alerte ADD piece VARCHAR(255) NOT NULL DEFAULT \'\', ADD niveau_pourcent INT DEFAULT NULL');
            $this->addSql('UPDATE alerte SET piece = etat WHERE etat IS NOT NULL AND etat != \'\'');
            $this->addSql('ALTER TABLE alerte DROP etat');
            $this->addSql('ALTER TABLE alerte ALTER piece DROP DEFAULT');
        } else {
            $this->addSql('ALTER TABLE alerte ADD COLUMN piece VARCHAR(255) NOT NULL DEFAULT \'\'');
            $this->addSql('ALTER TABLE alerte ADD COLUMN niveau_pourcent INT DEFAULT NULL');
            $this->addSql('UPDATE alerte SET piece = etat WHERE etat IS NOT NULL AND etat != \'\'');
            $this->addSql('CREATE TABLE alerte_new (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, message_id VARCHAR(255) DEFAULT NULL, sujet VARCHAR(500) NOT NULL, expediteur VARCHAR(255) NOT NULL, recu_le DATETIME DEFAULT NULL, site VARCHAR(255) NOT NULL, modele_imprimante VARCHAR(255) NOT NULL, numero_serie VARCHAR(100) NOT NULL, motif_alerte CLOB NOT NULL, piece VARCHAR(255) NOT NULL, niveau_pourcent INT DEFAULT NULL, created_at DATETIME NOT NULL)');
            $this->addSql('INSERT INTO alerte_new (id, message_id, sujet, expediteur, recu_le, site, modele_imprimante, numero_serie, motif_alerte, piece, niveau_pourcent, created_at) SELECT id, message_id, sujet, expediteur, recu_le, site, modele_imprimante, numero_serie, motif_alerte, piece, niveau_pourcent, created_at FROM alerte');
            $this->addSql('DROP TABLE alerte');
            $this->addSql('ALTER TABLE alerte_new RENAME TO alerte');
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof MySQLPlatform) {
            $this->addSql('ALTER TABLE alerte ADD etat VARCHAR(255) NOT NULL DEFAULT \'\'');
            $this->addSql('UPDATE alerte SET etat = piece');
            $this->addSql('ALTER TABLE alerte DROP piece, DROP niveau_pourcent');
            $this->addSql('ALTER TABLE alerte ALTER etat DROP DEFAULT');
        } else {
            $this->addSql('ALTER TABLE alerte ADD COLUMN etat VARCHAR(255) NOT NULL DEFAULT \'\'');
            $this->addSql('UPDATE alerte SET etat = piece');
            $this->addSql('CREATE TABLE alerte_old AS SELECT id, message_id, sujet, expediteur, recu_le, site, modele_imprimante, numero_serie, motif_alerte, etat, created_at FROM alerte');
            $this->addSql('DROP TABLE alerte');
            $this->addSql('CREATE TABLE alerte (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, message_id VARCHAR(255) DEFAULT NULL, sujet VARCHAR(500) NOT NULL, expediteur VARCHAR(255) NOT NULL, recu_le DATETIME DEFAULT NULL, site VARCHAR(255) NOT NULL, modele_imprimante VARCHAR(255) NOT NULL, numero_serie VARCHAR(100) NOT NULL, motif_alerte CLOB NOT NULL, etat VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL)');
            $this->addSql('INSERT INTO alerte SELECT id, message_id, sujet, expediteur, recu_le, site, modele_imprimante, numero_serie, motif_alerte, etat, created_at FROM alerte_old');
            $this->addSql('DROP TABLE alerte_old');
        }
    }
}
