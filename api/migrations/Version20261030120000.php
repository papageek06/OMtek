<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Droits fins par utilisateur (user.permissions) et coffre personnel chiffre (user_vault_entry).
 * Retrocompatible: les utilisateurs existants n'ont aucun droit fin en plus (les admins ont tout via role_hierarchy),
 * colonnes de deverrouillage nullables ou avec valeur par defaut.
 */
final class Version20261030120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les droits fins utilisateur et le coffre personnel chiffre.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform && !$platform instanceof SqlitePlatform) {
            $this->abortIf(true, 'Plateforme non supportee pour cette migration.');
        }

        if ($platform instanceof SqlitePlatform) {
            $this->addSql("ALTER TABLE user ADD permissions CLOB DEFAULT '[]' NOT NULL");
            $this->addSql('ALTER TABLE user ADD vault_unlock_token_hash VARCHAR(64) DEFAULT NULL');
            $this->addSql('ALTER TABLE user ADD vault_unlock_expires_at DATETIME DEFAULT NULL');
            $this->addSql('ALTER TABLE user ADD vault_failed_attempts SMALLINT DEFAULT 0 NOT NULL');
            $this->addSql('ALTER TABLE user ADD vault_locked_until DATETIME DEFAULT NULL');
            $this->addSql('CREATE TABLE user_vault_entry (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_id INTEGER NOT NULL, type VARCHAR(20) NOT NULL, data_encrypted CLOB NOT NULL, secret_encrypted CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, CONSTRAINT FK_DFA2989EA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE)');
            $this->addSql('CREATE INDEX idx_user_vault_entry_user ON user_vault_entry (user_id)');

            return;
        }

        // JSON sans valeur par defaut en MySQL: ajout nullable, remplissage, puis NOT NULL.
        $this->addSql('ALTER TABLE `user` ADD permissions JSON DEFAULT NULL, ADD vault_unlock_token_hash VARCHAR(64) DEFAULT NULL, ADD vault_unlock_expires_at DATETIME DEFAULT NULL, ADD vault_failed_attempts SMALLINT DEFAULT 0 NOT NULL, ADD vault_locked_until DATETIME DEFAULT NULL');
        $this->addSql("UPDATE `user` SET permissions = JSON_ARRAY() WHERE permissions IS NULL");
        $this->addSql('ALTER TABLE `user` MODIFY permissions JSON NOT NULL');
        $this->addSql('CREATE TABLE user_vault_entry (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, data_encrypted LONGTEXT NOT NULL, secret_encrypted LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX idx_user_vault_entry_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE user_vault_entry ADD CONSTRAINT FK_DFA2989EA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform && !$platform instanceof SqlitePlatform) {
            $this->abortIf(true, 'Plateforme non supportee pour cette migration.');
        }

        // Attention: supprime definitivement le contenu des coffres personnels.
        $this->addSql('DROP TABLE user_vault_entry');
        foreach (['vault_locked_until', 'vault_failed_attempts', 'vault_unlock_expires_at', 'vault_unlock_token_hash', 'permissions'] as $column) {
            $this->addSql(sprintf('ALTER TABLE %s DROP %s', $platform instanceof AbstractMySQLPlatform ? '`user`' : 'user', $column));
        }
    }
}
