<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replace theme column with themeThursday boolean and themeThursdayTitle
 */
final class Version20260817150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace theme column with themeThursday boolean and themeThursdayTitle';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playlist DROP theme');
        $this->addSql('ALTER TABLE playlist ADD theme_thursday BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE playlist ADD theme_thursday_title VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playlist DROP theme_thursday');
        $this->addSql('ALTER TABLE playlist DROP theme_thursday_title');
        $this->addSql('ALTER TABLE playlist ADD theme VARCHAR(50) DEFAULT NULL');
    }
}