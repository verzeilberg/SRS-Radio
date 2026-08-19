<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add theme_vote table for Theme Thursday voting
 */
final class Version20260817100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create theme_vote table for Theme Thursday voting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE theme_vote (
            id INT AUTO_INCREMENT NOT NULL,
            theme VARCHAR(50) NOT NULL,
            week DATE NOT NULL,
            voter VARCHAR(100) NOT NULL,
            voted_at DATETIME NOT NULL,
            UNIQUE INDEX unique_voter_per_week (week, voter),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE theme_vote');
    }
}