<?php
namespace App\Entity;

use App\Repository\SongRequestRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SongRequestRepository::class)]
#[ORM\Table(name: 'song_request')]
class SongRequest
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PLAYED   = 'played';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $spotifyId;

    #[ORM\Column(length: 128)]
    private string $spotifyUri;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 255)]
    private string $artist;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $imageUrl;

    #[ORM\Column(length: 128)]
    private string $requestedBy;

    #[ORM\Column]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    public function __construct(
        string $spotifyId,
        string $spotifyUri,
        string $title,
        string $artist,
        ?string $imageUrl,
        string $requestedBy,
    ) {
        $this->spotifyId   = $spotifyId;
        $this->spotifyUri  = $spotifyUri;
        $this->title       = $title;
        $this->artist      = $artist;
        $this->imageUrl    = $imageUrl;
        $this->requestedBy = $requestedBy;
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int                       { return $this->id; }
    public function getSpotifyId(): string              { return $this->spotifyId; }
    public function getSpotifyUri(): string             { return $this->spotifyUri; }
    public function getTitle(): string                  { return $this->title; }
    public function getArtist(): string                 { return $this->artist; }
    public function getImageUrl(): ?string              { return $this->imageUrl; }
    public function getRequestedBy(): string            { return $this->requestedBy; }
    public function getRequestedAt(): \DateTimeImmutable { return $this->requestedAt; }
    public function getStatus(): string                 { return $this->status; }
    public function getApprovedAt(): ?\DateTimeImmutable { return $this->approvedAt; }

    public function approve(): void
    {
        $this->status     = self::STATUS_APPROVED;
        $this->approvedAt = new \DateTimeImmutable();
    }

    public function reject(): void
    {
        $this->status = self::STATUS_REJECTED;
    }

    public function markPlayed(): void
    {
        $this->status = self::STATUS_PLAYED;
    }
}
