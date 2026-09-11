<?php
namespace App\Repository;

use App\Entity\ThemeThursdayOption;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ThemeThursdayOption>
 */
class ThemeThursdayOptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ThemeThursdayOption::class);
    }

    /** Returns active Theme Thursday options ordered by sortOrder. */
    public function findActiveOptions(): array
    {
        return $this->findBy(['active' => true], ['sortOrder' => 'ASC', 'id' => 'ASC']);
    }

    /** Returns distinct titles from active options for voting. */
    public function findAvailableTitles(): array
    {
        return $this->createQueryBuilder('o')
            ->select('DISTINCT o.title')
            ->andWhere('o.active = true')
            ->orderBy('o.title', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /** Returns active options grouped by title. */
    public function findOptionsByTitle(): array
    {
        $options = $this->findActiveOptions();

        $grouped = [];
        foreach ($options as $o) {
            $title = $o->getTitle();
            if (!isset($grouped[$title])) {
                $grouped[$title] = [];
            }
            $grouped[$title][] = [
                'id'    => $o->getSpotifyId(),
                'label' => $o->getLabel(),
            ];
        }

        return $grouped;
    }

    /** Find options by title for Theme Thursday playback. */
    public function findByTitle(string $title): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.active = true')
            ->andWhere('o.title = :title')
            ->setParameter('title', $title)
            ->orderBy('o.sortOrder', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}