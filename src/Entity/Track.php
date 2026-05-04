<?php
namespace App\Entity;

use App\Repository\TrackRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TrackRepository::class)]
#[ORM\Table(name: 'track')]
class Track
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 255)]
    private string $artist;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $spotifyId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $djText = null;

    #[ORM\Column]
    private \DateTimeImmutable $playedAt;

    public function __construct(string $title, string $artist, ?string $spotifyId = null, ?string $djText = null)
    {
        $this->title = $title;
        $this->artist = $artist;
        $this->spotifyId = $spotifyId;
        $this->djText = $djText;
        $this->playedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function getArtist(): string { return $this->artist; }
    public function getSpotifyId(): ?string { return $this->spotifyId; }
    public function getDjText(): ?string { return $this->djText; }
    public function getPlayedAt(): \DateTimeImmutable { return $this->playedAt; }
}
