<?php
namespace App\Repository;

use App\Entity\Track;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TrackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Track::class);
    }

    public function findRecentSpotifyIds(int $limit = 15): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.spotifyId')
            ->where('t.spotifyId IS NOT NULL')
            ->orderBy('t.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'spotifyId');
    }

    public function findSpotifyIdsPlayedSince(\DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.spotifyId')
            ->where('t.spotifyId IS NOT NULL')
            ->andWhere('t.playedAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'spotifyId');
    }

    public function findLatest(): ?Track
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findPreviousTrack(): ?string
    {
        return $this->createQueryBuilder('t')
            ->select('t.title')
            ->orderBy('t.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(\Doctrine\ORM\AbstractQuery::HYDRATE_SINGLE_SCALAR);
    }
}
