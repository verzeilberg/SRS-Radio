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
        $rsm = new \Doctrine\ORM\Query\ResultSetMappingBuilder($this->getEntityManager());
        $rsm->addRootEntityFromClassMetadata(Colleague::class, 'c');

        $sql = 'SELECT ' . $rsm->generateSelectClause() . ' FROM colleague c ORDER BY MONTH(c.birthdate) ASC, DAY(c.birthdate) ASC, c.name ASC';

        return $this->getEntityManager()
            ->createNativeQuery($sql, $rsm)
            ->getResult();
    }

    /** @return Colleague[] */
    public function findUpcomingBirthdays(int $limit = 3): array
    {
        $now = new \DateTimeImmutable('today');

        $all = $this->findAllOrderedByBirthday();

        $upcoming = [];
        foreach ($all as $c) {
            $bdayMonth = (int) $c->getBirthdate()->format('m');
            $bdayDay = (int) $c->getBirthdate()->format('d');

            // Check if birthday is today or in the future this year
            $thisYearBday = new \DateTimeImmutable($now->format('Y') . '-' . sprintf('%02d', $bdayMonth) . '-' . sprintf('%02d', $bdayDay));
            if ($thisYearBday < $now) {
                // Already passed this year, check next year
                $thisYearBday = new \DateTimeImmutable(($now->format('Y') + 1) . '-' . sprintf('%02d', $bdayMonth) . '-' . sprintf('%02d', $bdayDay));
            }

            $diff = $now->diff($thisYearBday);
            $c->daysUntil = (int) $diff->format('%a');
            $upcoming[] = $c;
        }

        // Sort by days until birthday (today = 0 first)
        usort($upcoming, fn($a, $b) => $a->daysUntil <=> $b->daysUntil);

        return array_slice($upcoming, 0, $limit);
    }
}
