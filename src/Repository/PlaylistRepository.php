<?php
namespace App\Repository;

use App\Entity\Playlist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Playlist>
 */
class PlaylistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Playlist::class);
    }

    /** Returns active playlists as ['id' => spotifyId, 'label' => label] arrays for RadioStartCommand. */
    public function findActivePools(): array
    {
        $playlists = $this->findBy(['active' => true], ['sortOrder' => 'ASC', 'id' => 'ASC']);

        return array_map(fn(Playlist $p) => [
            'id'    => $p->getSpotifyId(),
            'label' => $p->getLabel(),
        ], $playlists);
    }

    /** Returns active playlists tagged for Theme Thursday, optionally filtered by title. */
    public function findThemeThursday(?string $title = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.active = true')
            ->andWhere('p.themeThursday = true')
            ->orderBy('p.sortOrder', 'ASC')
            ->addOrderBy('p.id', 'ASC');

        if ($title !== null) {
            $qb->andWhere('p.themeThursdayTitle = :title')
               ->setParameter('title', $title);
        }

        $playlists = $qb->getQuery()->getResult();

        return array_map(fn(Playlist $p) => [
            'id'    => $p->getSpotifyId(),
            'label' => $p->getLabel(),
        ], $playlists);
    }

    /** Returns distinct themeThursdayTitle values from active playlists tagged for Theme Thursday. */
    public function findAvailableThemeThursdayTitles(): array
    {
        return $this->createQueryBuilder('p')
            ->select('DISTINCT p.themeThursdayTitle')
            ->andWhere('p.active = true')
            ->andWhere('p.themeThursday = true')
            ->andWhere('p.themeThursdayTitle IS NOT NULL')
            ->andWhere('p.themeThursdayTitle != \'\'')
            ->orderBy('p.themeThursdayTitle', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /** Returns active playlists tagged for Theme Thursday, grouped by themeThursdayTitle. */
    public function findThemeThursdayPlaylistsByTitle(): array
    {
        $playlists = $this->createQueryBuilder('p')
            ->andWhere('p.active = true')
            ->andWhere('p.themeThursday = true')
            ->andWhere('p.themeThursdayTitle IS NOT NULL')
            ->andWhere('p.themeThursdayTitle != \'\'')
            ->orderBy('p.themeThursdayTitle', 'ASC')
            ->addOrderBy('p.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($playlists as $p) {
            $title = $p->getThemeThursdayTitle();
            if (!isset($grouped[$title])) {
                $grouped[$title] = [];
            }
            $grouped[$title][] = [
                'id'    => $p->getSpotifyId(),
                'label' => $p->getLabel(),
            ];
        }

        return $grouped;
    }
}