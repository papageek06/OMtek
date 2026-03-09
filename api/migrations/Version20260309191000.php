<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260309191000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Aligne les noms d indexes avec le schema Doctrine attendu.';
    }

    public function up(Schema $schema): void
    {
        $this->renameIndexIfNeeded('modele_piece', 'IDX_modele_piece_modele', 'IDX_E106393CAC14B70A');
        $this->renameIndexIfNeeded('modele_piece', 'IDX_modele_piece_piece', 'IDX_E106393CC40FCFA8');
        $this->renameIndexIfNeeded('intervention', 'FK_intervention_imprimante', 'IDX_D11814AB1CA0A76');
        $this->renameIndexIfNeeded('intervention', 'FK_intervention_created_by', 'IDX_D11814AB7D182D95');
        $this->renameIndexIfNeeded('intervention', 'FK_intervention_source_alerte', 'IDX_D11814AB440535E7');
        $this->renameIndexIfNeeded('stock_movement', 'FK_stock_movement_stock', 'IDX_BB1BC1B5DCD6110');
        $this->renameIndexIfNeeded('stock_movement', 'FK_stock_movement_user', 'IDX_BB1BC1B5A76ED395');
    }

    public function down(Schema $schema): void
    {
        $this->renameIndexIfNeeded('modele_piece', 'IDX_E106393CAC14B70A', 'IDX_modele_piece_modele');
        $this->renameIndexIfNeeded('modele_piece', 'IDX_E106393CC40FCFA8', 'IDX_modele_piece_piece');
        $this->renameIndexIfNeeded('intervention', 'IDX_D11814AB1CA0A76', 'FK_intervention_imprimante');
        $this->renameIndexIfNeeded('intervention', 'IDX_D11814AB7D182D95', 'FK_intervention_created_by');
        $this->renameIndexIfNeeded('intervention', 'IDX_D11814AB440535E7', 'FK_intervention_source_alerte');
        $this->renameIndexIfNeeded('stock_movement', 'IDX_BB1BC1B5DCD6110', 'FK_stock_movement_stock');
        $this->renameIndexIfNeeded('stock_movement', 'IDX_BB1BC1B5A76ED395', 'FK_stock_movement_user');
    }

    private function renameIndexIfNeeded(string $table, string $from, string $to): void
    {
        $indexNames = array_map(
            static fn (array $row): string => (string) $row['Key_name'],
            $this->connection->fetchAllAssociative(sprintf('SHOW INDEX FROM `%s`', $table))
        );

        if (\in_array($to, $indexNames, true) || !\in_array($from, $indexNames, true)) {
            return;
        }

        $this->addSql(sprintf('ALTER TABLE `%s` RENAME INDEX `%s` TO `%s`', $table, $from, $to));
    }
}
