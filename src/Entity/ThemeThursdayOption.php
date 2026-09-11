<?php
namespace App\Entity;

use App\Repository\ThemeThursdayOptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ThemeThursdayOptionRepository::class)]
#[ORM\Table(name: 'theme_thursday_option')]
class ThemeThursdayOption
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $spotifyId;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(length: 100)]
    private string $title;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $spotifyId, string $label, string $title, int $sortOrder = 0)
    {
        $this->spotifyId  = $spotifyId;
        $this->label      = $label;
        $this->title      = $title;
        $this->sortOrder  = $sortOrder;
        $this->createdAt  = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSpotifyId(): string { return $this->spotifyId; }
    public function getLabel(): string { return $this->label; }
    public function getTitle(): string { return $this->title; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function isActive(): bool { return $this->active; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function setLabel(string $label): void { $this->label = $label; }
    public function setTitle(string $title): void { $this->title = $title; }
    public function setSortOrder(int $order): void { $this->sortOrder = $order; }
    public function setActive(bool $active): void { $this->active = $active; }
}