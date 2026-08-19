<?php

namespace App\Repository;

use App\Entity\ThemeVote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ThemeVoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ThemeVote::class);
    }

    public function findWinningTheme(string $weekStart): ?ThemeVote
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT tv.theme, COUNT(*) as votes
            FROM theme_vote tv
            WHERE tv.week = :week
            GROUP BY tv.theme
            ORDER BY votes DESC, tv.theme ASC
            LIMIT 1
        ';

        $result = $conn->fetchAssociative($sql, ['week' => $weekStart]);

        if (!$result) {
            return null;
        }

        $vote = new ThemeVote($result['theme'], new \DateTimeImmutable($weekStart), '');
        return $vote;
    }

    public function getVoteCounts(string $weekStart): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT tv.theme, COUNT(*) as votes
            FROM theme_vote tv
            WHERE tv.week = :week
            GROUP BY tv.theme
            ORDER BY votes DESC, tv.theme ASC
        ';

        return $conn->fetchAllAssociative($sql, ['week' => $weekStart]);
    }

    public function hasVoted(string $weekStart, string $voter): bool
    {
        return $this->createQueryBuilder('tv')
            ->select('COUNT(tv.id)')
            ->andWhere('tv.week = :week')
            ->andWhere('tv.voter = :voter')
            ->setParameter('week', $weekStart)
            ->setParameter('voter', $voter)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function findCurrentWeekVotes(): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Amsterdam'));
        $monday = $now->modify('monday this week')->format('Y-m-d');

        return $this->getVoteCounts($monday);
    }

    public function isVotingOpen(): bool
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Amsterdam'));
        $dayOfWeek = (int) $now->format('N'); // 1=Mon, 7=Sun

        return $dayOfWeek >= 1 && $dayOfWeek <= 3; // Mon-Wed
    }
}