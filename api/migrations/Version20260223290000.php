<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260223290000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Piece: ajout colonne ref_bis (référence secondaire / entreprise).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE piece ADD ref_bis VARCHAR(80) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE piece DROP ref_bis');
    }
}
