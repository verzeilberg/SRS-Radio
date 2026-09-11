<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create theme_thursday_option table for separate Theme Thursday voting playlists
 */
final class Version20260903100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create theme_thursday_option table for separate Theme Thursday voting playlists';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE theme_thursday_option (
            id INT AUTO_INCREMENT NOT NULL,
            spotify_id VARCHAR(255) NOT NULL,
            label VARCHAR(255) NOT NULL,
            title VARCHAR(100) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE theme_thursday_option');
    }
}