<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260823170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la configuration du moteur alertes encre et la trace de calcul active/inactive.';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform && !$platform instanceof SqlitePlatform) {
            $this->abortIf(true, 'Plateforme non supportee pour cette migration.');
        }

        if (!$schema->hasTable('alert_rule_config')) {
            if ($platform instanceof SqlitePlatform) {
                $this->addSql("CREATE TABLE alert_rule_config (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    mode VARCHAR(40) DEFAULT 'CURRENT_RULE' NOT NULL,
                    min_printers INTEGER DEFAULT 2 NOT NULL,
                    simple_status_only BOOLEAN DEFAULT 1 NOT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL
                )");
            } else {
                $this->addSql("CREATE TABLE alert_rule_config (
                    id INT AUTO_INCREMENT NOT NULL,
                    mode VARCHAR(40) DEFAULT 'CURRENT_RULE' NOT NULL,
                    min_printers INT DEFAULT 2 NOT NULL,
                    simple_status_only TINYINT(1) DEFAULT 1 NOT NULL,
                    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
            }
        }

        if (!$schema->hasTable('alert_rule_threshold')) {
            if ($platform instanceof SqlitePlatform) {
                $this->addSql("CREATE TABLE alert_rule_threshold (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    config_id INTEGER NOT NULL,
                    min_percent INTEGER NOT NULL,
                    max_percent INTEGER NOT NULL,
                    label VARCHAR(80) NOT NULL,
                    weight NUMERIC(5, 2) NOT NULL,
                    sort_order INTEGER DEFAULT 0 NOT NULL,
                    FOREIGN KEY(config_id) REFERENCES alert_rule_config (id) ON DELETE CASCADE
                )");
                $this->addSql('CREATE INDEX idx_alert_rule_threshold_config ON alert_rule_threshold (config_id)');
            } else {
                $this->addSql("CREATE TABLE alert_rule_threshold (
                    id INT AUTO_INCREMENT NOT NULL,
                    config_id INT NOT NULL,
                    min_percent INT NOT NULL,
                    max_percent INT NOT NULL,
                    label VARCHAR(80) NOT NULL,
                    weight NUMERIC(5, 2) NOT NULL,
                    sort_order INT DEFAULT 0 NOT NULL,
                    INDEX idx_alert_rule_threshold_config (config_id),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
                $this->addSql('ALTER TABLE alert_rule_threshold ADD CONSTRAINT FK_5E5A3FE624DB0683 FOREIGN KEY (config_id) REFERENCES alert_rule_config (id) ON DELETE CASCADE');
            }
        }

        if ($schema->hasTable('alerte')) {
            $table = $schema->getTable('alerte');
            $boolType = $platform instanceof SqlitePlatform ? 'BOOLEAN' : 'TINYINT(1)';
            $evaluatedAtSql = $platform instanceof SqlitePlatform
                ? 'ALTER TABLE alerte ADD rule_evaluated_at DATETIME DEFAULT NULL'
                : "ALTER TABLE alerte ADD rule_evaluated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'";
            $columns = [
                'auto_deactivated' => 'ALTER TABLE alerte ADD auto_deactivated ' . $boolType . ' DEFAULT 0 NOT NULL',
                'active_manual_override' => 'ALTER TABLE alerte ADD active_manual_override ' . $boolType . ' DEFAULT NULL',
                'rule_mode' => 'ALTER TABLE alerte ADD rule_mode VARCHAR(40) DEFAULT NULL',
                'rule_reason' => 'ALTER TABLE alerte ADD rule_reason VARCHAR(80) DEFAULT NULL',
                'rule_score' => 'ALTER TABLE alerte ADD rule_score NUMERIC(6, 2) DEFAULT NULL',
                'rule_stock_quantity' => 'ALTER TABLE alerte ADD rule_stock_quantity INT DEFAULT NULL',
                'rule_evaluated_at' => $evaluatedAtSql,
            ];
            foreach ($columns as $column => $sql) {
                if (!$table->hasColumn($column)) {
                    $this->addSql($sql);
                }
            }
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->addSql("INSERT INTO alert_rule_config (id, mode, min_printers, simple_status_only, created_at, updated_at)
            SELECT 1, 'CURRENT_RULE', 2, 1, '$now', '$now'
            WHERE NOT EXISTS (SELECT 1 FROM alert_rule_config)");

        foreach ($this->defaultThresholds() as $row) {
            $this->addSql(sprintf(
                "INSERT INTO alert_rule_threshold (config_id, min_percent, max_percent, label, weight, sort_order)
                SELECT 1, %d, %d, '%s', %.2F, %d
                WHERE NOT EXISTS (SELECT 1 FROM alert_rule_threshold WHERE config_id = 1 AND sort_order = %d)",
                $row['min'],
                $row['max'],
                $row['label'],
                $row['weight'],
                $row['order'],
                $row['order'],
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform && !$platform instanceof SqlitePlatform) {
            $this->abortIf(true, 'Plateforme non supportee pour cette migration.');
        }

        if ($schema->hasTable('alerte')) {
            $table = $schema->getTable('alerte');
            foreach ([
                'rule_evaluated_at',
                'rule_stock_quantity',
                'rule_score',
                'rule_reason',
                'rule_mode',
                'active_manual_override',
                'auto_deactivated',
            ] as $column) {
                if ($table->hasColumn($column)) {
                    $this->addSql(sprintf('ALTER TABLE alerte DROP %s', $column));
                }
            }
        }

        if ($schema->hasTable('alert_rule_threshold')) {
            $this->addSql('DROP TABLE alert_rule_threshold');
        }
        if ($schema->hasTable('alert_rule_config')) {
            $this->addSql('DROP TABLE alert_rule_config');
        }
    }

    /**
     * @return list<array{min:int,max:int,label:string,weight:float,order:int}>
     */
    private function defaultThresholds(): array
    {
        return [
            ['min' => 0, 'max' => 10, 'label' => 'Urgent', 'weight' => 1.00, 'order' => 1],
            ['min' => 11, 'max' => 20, 'label' => 'Alerte recue', 'weight' => 1.00, 'order' => 2],
            ['min' => 21, 'max' => 30, 'label' => 'Tres bas', 'weight' => 0.75, 'order' => 3],
            ['min' => 31, 'max' => 40, 'label' => 'Risque proche', 'weight' => 0.50, 'order' => 4],
            ['min' => 41, 'max' => 50, 'label' => 'Surveillance', 'weight' => 0.25, 'order' => 5],
            ['min' => 51, 'max' => 100, 'label' => 'OK', 'weight' => 0.00, 'order' => 6],
        ];
    }
}
