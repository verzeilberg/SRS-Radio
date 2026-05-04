<?php
namespace App\Entity;

use App\Repository\DjAnnouncementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DjAnnouncementRepository::class)]
#[ORM\Table(name: 'dj_announcement')]
class DjAnnouncement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    #[ORM\Column(length: 512)]
    private string $audioUrl;

    // between_tracks | morning | lunch | afternoon | end_of_day
    #[ORM\Column(length: 32)]
    private string $type;

    #[ORM\Column]
    private \DateTimeImmutable $playedAt;

    public function __construct(string $text, string $audioUrl, string $type)
    {
        $this->text     = $text;
        $this->audioUrl = $audioUrl;
        $this->type     = $type;
        $this->playedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getText(): string { return $this->text; }
    public function getAudioUrl(): string { return $this->audioUrl; }
    public function getType(): string { return $this->type; }
    public function getPlayedAt(): \DateTimeImmutable { return $this->playedAt; }
}
