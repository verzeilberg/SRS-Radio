<?php

namespace App\Entity;

use App\Repository\ThemeVoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ThemeVoteRepository::class)]
#[ORM\Table(name: 'theme_vote')]
#[ORM\UniqueConstraint(name: 'unique_voter_per_week', columns: ['week', 'voter'])]
class ThemeVote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $theme;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $week;

    #[ORM\Column(length: 100)]
    private string $voter;

    #[ORM\Column]
    private \DateTimeImmutable $votedAt;

    public function __construct(string $theme, \DateTimeImmutable $week, string $voter)
    {
        $this->theme = $theme;
        $this->week = $week;
        $this->voter = $voter;
        $this->votedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTheme(): string
    {
        return $this->theme;
    }

    public function getWeek(): \DateTimeImmutable
    {
        return $this->week;
    }

    public function getVoter(): string
    {
        return $this->voter;
    }

    public function getVotedAt(): \DateTimeImmutable
    {
        return $this->votedAt;
    }
}