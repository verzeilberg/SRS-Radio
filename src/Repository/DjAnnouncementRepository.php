<?php
namespace App\Repository;

use App\Entity\DjAnnouncement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DjAnnouncementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DjAnnouncement::class);
    }

    public function findRecentTexts(string $type, int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->select('a.text')
            ->where('a.type = :type')
            ->setParameter('type', $type)
            ->orderBy('a.playedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();
    }
}
