<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260520101035 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE playlist (id INT AUTO_INCREMENT NOT NULL, spotify_id VARCHAR(255) NOT NULL, label VARCHAR(255) NOT NULL, active TINYINT NOT NULL, sort_order INT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        // Seed the hardcoded playlists from services.yaml so the pool works immediately.
        $playlists = [
            ['1VGeD6EPilAaVp79AdPhtE', 'Mega Hit Mix',   0],
            ['3Me8NYy0WVXf6ZGX9khgEq', 'All Out 80s',    1],
            ['6U7QcTtyipCtL17Su1bxgj', 'All Out 90s',    2],
            ['7eMpggGjI2UdYxmqMgWzfw', 'All Out 00s',    3],
            ['1t0F16J7AC0ajHmGv7ihsh', 'All Out 10s',    4],
            ['54i1eiCTqTbXnjSPRpWTu8', 'All Out 2020s',  5],
            ['1SlAib5BO2Bb90Auow2rOd', 'Koningsdag 2026', 6],
        ];
        foreach ($playlists as [$spotifyId, $label, $sortOrder]) {
            $this->addSql(
                'INSERT INTO playlist (spotify_id, label, active, sort_order) VALUES (?, ?, 1, ?)',
                [$spotifyId, $label, $sortOrder],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE playlist');
    }
}
