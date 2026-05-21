<?php
namespace App\Entity;

use App\Repository\PlaylistRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlaylistRepository::class)]
class Playlist
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $spotifyId;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private int $sortOrder = 0;

    public function __construct(string $spotifyId, string $label, int $sortOrder = 0)
    {
        $this->spotifyId  = $spotifyId;
        $this->label      = $label;
        $this->sortOrder  = $sortOrder;
    }

    public function getId(): ?int { return $this->id; }
    public function getSpotifyId(): string { return $this->spotifyId; }
    public function getLabel(): string { return $this->label; }
    public function isActive(): bool { return $this->active; }
    public function getSortOrder(): int { return $this->sortOrder; }

    public function setLabel(string $label): void { $this->label = $label; }
    public function setActive(bool $active): void { $this->active = $active; }
    public function setSortOrder(int $order): void { $this->sortOrder = $order; }
}