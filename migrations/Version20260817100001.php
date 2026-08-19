<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add theme column to playlist table
 */
final class Version20260817100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add theme column to playlist table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playlist ADD theme VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playlist DROP theme');
    }
}