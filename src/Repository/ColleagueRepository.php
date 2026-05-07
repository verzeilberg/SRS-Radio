<?php
namespace App\Repository;

use App\Entity\Colleague;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ColleagueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Colleague::class);
    }

    /** @return Colleague[] */
    public function findTodaysBirthdays(): array
    {
        $today = new \DateTimeImmutable('today');

        return $this->createQueryBuilder('c')
            ->where('MONTH(c.birthdate) = :month')
            ->andWhere('DAY(c.birthdate) = :day')
            ->setParameter('month', (int) $today->format('m'))
            ->setParameter('day',   (int) $today->format('d'))
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
