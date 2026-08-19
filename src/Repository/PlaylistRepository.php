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

    /** Returns active playlists tagged for Theme Thursday. */
    public function findThemeThursday(): array
    {
        $playlists = $this->createQueryBuilder('p')
            ->andWhere('p.active = true')
            ->andWhere('p.themeThursday = true')
            ->orderBy('p.sortOrder', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(fn(Playlist $p) => [
            'id'    => $p->getSpotifyId(),
            'label' => $p->getLabel(),
        ], $playlists);
    }
}