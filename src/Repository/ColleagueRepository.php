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
        $month = (int) $today->format('m');
        $day   = (int) $today->format('d');

        return array_values(array_filter(
            $this->findBy([], ['name' => 'ASC']),
            fn(Colleague $c) => (int) $c->getBirthdate()->format('m') === $month
                             && (int) $c->getBirthdate()->format('d') === $day,
        ));
    }

    /** @return Colleague[] */
    public function findAllOrderedByBirthday(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('MONTH(c.birthdate)', 'ASC')
            ->addOrderBy('DAY(c.birthdate)', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
