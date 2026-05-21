<?php
namespace App\Repository;

use App\Entity\SongRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SongRequest>
 */
class SongRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SongRequest::class);
    }

    /** @return SongRequest[] */
    public function findPending(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :status')
            ->setParameter('status', SongRequest::STATUS_PENDING)
            ->orderBy('r.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findNextApproved(): ?SongRequest
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :status')
            ->setParameter('status', SongRequest::STATUS_APPROVED)
            ->orderBy('r.approvedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return SongRequest[] */
    public function findRecent(int $limit = 20): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.requestedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
