<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'spotify_token')]
class SpotifyToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 512)]
    private string $accessToken;

    #[ORM\Column(length: 512)]
    private string $refreshToken;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    public function __construct(string $accessToken, string $refreshToken, int $expiresIn)
    {
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
        $this->expiresAt = new \DateTimeImmutable('+' . $expiresIn . ' seconds');
    }

    public function getId(): ?int { return $this->id; }
    public function getAccessToken(): string { return $this->accessToken; }
    public function getRefreshToken(): string { return $this->refreshToken; }
    public function isExpired(): bool { return $this->expiresAt <= new \DateTimeImmutable(); }

    public function update(string $accessToken, int $expiresIn): void
    {
        $this->accessToken = $accessToken;
        $this->expiresAt = new \DateTimeImmutable('+' . $expiresIn . ' seconds');
    }
}
