<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260511132059 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE song_request (id INT AUTO_INCREMENT NOT NULL, spotify_id VARCHAR(64) NOT NULL, spotify_uri VARCHAR(128) NOT NULL, title VARCHAR(255) NOT NULL, artist VARCHAR(255) NOT NULL, image_url VARCHAR(512) DEFAULT NULL, requested_by VARCHAR(128) NOT NULL, requested_at DATETIME NOT NULL, status VARCHAR(16) NOT NULL, approved_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE song_request');
    }
}
