<?php
namespace App\Entity;

use App\Repository\ColleagueRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ColleagueRepository::class)]
#[ORM\Table(name: 'colleague')]
class Colleague
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $birthdate;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $picture = null;

    public function __construct(string $name, \DateTimeImmutable $birthdate, ?string $picture = null)
    {
        $this->name      = $name;
        $this->birthdate = $birthdate;
        $this->picture   = $picture;
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getBirthdate(): \DateTimeImmutable { return $this->birthdate; }
    public function getPicture(): ?string { return $this->picture; }

    public function setName(string $name): void { $this->name = $name; }
    public function setBirthdate(\DateTimeImmutable $birthdate): void { $this->birthdate = $birthdate; }
    public function setPicture(?string $picture): void { $this->picture = $picture; }
}
