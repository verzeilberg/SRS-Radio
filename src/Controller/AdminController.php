<?php

namespace App\Controller;

use App\Command\RadioStartCommand;
use App\Entity\Playlist;
use App\Repository\ColleagueRepository;
use App\Repository\PlaylistRepository;
use App\Repository\SongRequestRepository;
use App\Repository\UserRepository;
use App\Service\RadioStateService;
use App\Service\SonosApiService;
use App\Service\SonosService;
use App\Service\SpotifyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    public function __construct(
        private KernelInterface $kernel,
        private UserRepository $userRepository,
        private ColleagueRepository $colleagueRepository,
        private RadioStateService $radioState,
        private SonosService $sonos,
        private SonosApiService $sonosApi,
        private SpotifyService $spotify,
    ) {}

    #[Route('', name: 'app_admin_dashboard')]
    public function dashboard(): Response
    {
        ['volume' => $volume, 'backend' => $backend] = $this->fetchVolume();

        return $this->render('admin/index.html.twig', [
            'volume'          => $volume,
            'backend'         => $backend,
            'users'           => $this->userRepository->findAll(),
            'colleagues'      => $this->colleagueRepository->findBy([], ['name' => 'ASC']),
            'existing_images' => $this->scanColleagueImages(),
        ]);
    }

    #[Route('/api/volume', name: 'app_admin_volume_get', methods: ['GET'])]
    public function volumeGet(): JsonResponse
    {
        ['volume' => $volume, 'backend' => $backend] = $this->fetchVolume();
        return new JsonResponse(['volume' => $volume, 'backend' => $backend]);
    }

    #[Route('/api/volume/up', name: 'app_admin_volume_up', methods: ['POST'])]
    public function volumeUp(): JsonResponse
    {
        return $this->runVolumeCommand('up');
    }

    #[Route('/api/volume/down', name: 'app_admin_volume_down', methods: ['POST'])]
    public function volumeDown(): JsonResponse
    {
        return $this->runVolumeCommand('down');
    }

    #[Route('/api/start', name: 'app_admin_start', methods: ['POST'])]
    public function start(): JsonResponse
    {
        $pidFile = RadioStartCommand::pidFile();
        if (file_exists($pidFile)) {
            $existingPid = (int) trim(file_get_contents($pidFile));
            if ($existingPid > 0 && posix_kill($existingPid, 0)) {
                return new JsonResponse(['success' => false, 'message' => 'Radio is already running'], 400);
            }
            @unlink($pidFile);
        }

        $device = '';
        if (file_exists(RadioStartCommand::launchFile())) {
            $launch = json_decode(file_get_contents(RadioStartCommand::launchFile()), true);
            if (!empty($launch['device'])) {
                $device = ' --device=' . escapeshellarg($launch['device']);
            }
        }

        $cmd = sprintf(
            'nohup php %s/bin/console radio:start%s > /dev/null 2>&1 &',
            escapeshellarg($this->kernel->getProjectDir()),
            $device,
        );
        shell_exec($cmd);

        return new JsonResponse(['success' => true, 'message' => 'Radio starting…']);
    }

    #[Route('/api/stop', name: 'app_admin_stop', methods: ['POST'])]
    public function stop(): JsonResponse
    {
        if (!file_exists(RadioStartCommand::pidFile())) {
            return new JsonResponse(['success' => false, 'message' => 'Radio is not running'], 400);
        }

        file_put_contents(RadioStartCommand::stopFlagFile(), '1');

        return new JsonResponse(['success' => true, 'message' => 'Radio stopping…']);
    }

    #[Route('/api/next', name: 'app_admin_next', methods: ['POST'])]
    public function next(): JsonResponse
    {
        if (!file_exists(RadioStartCommand::pidFile())) {
            return new JsonResponse(['success' => false, 'message' => 'Radio is not running'], 400);
        }

        file_put_contents(RadioStartCommand::skipFlagFile(), '1');

        return new JsonResponse(['success' => true, 'message' => 'Skipped!']);
    }

    #[Route('/api/pause', name: 'app_admin_pause', methods: ['POST'])]
    public function pause(): JsonResponse
    {
        if (!file_exists(RadioStartCommand::pidFile())) {
            return new JsonResponse(['success' => false, 'message' => 'Radio is not running'], 400);
        }

        $pauseFile = RadioStartCommand::pauseFlagFile();

        if (file_exists($pauseFile)) {
            @unlink($pauseFile);
            return new JsonResponse(['success' => true, 'paused' => false, 'message' => 'Resumed']);
        }

        file_put_contents($pauseFile, '1');
        return new JsonResponse(['success' => true, 'paused' => true, 'message' => 'Paused']);
    }

    #[Route('/api/restart', name: 'app_admin_restart', methods: ['POST'])]
    public function restart(): JsonResponse
    {
        if (!file_exists(RadioStartCommand::pidFile())) {
            return new JsonResponse(['success' => false, 'message' => 'Radio is not running'], 400);
        }

        file_put_contents(RadioStartCommand::restartFlagFile(), '1');

        return new JsonResponse(['success' => true, 'message' => 'Restarting…']);
    }

    #[Route('/api/state', name: 'app_admin_state', methods: ['GET'])]
    public function state(): JsonResponse
    {
        return new JsonResponse([
            'running' => file_exists(RadioStartCommand::pidFile()),
            'paused'  => file_exists(RadioStartCommand::pauseFlagFile()),
        ]);
    }

    #[Route('/api/log', name: 'app_admin_log', methods: ['GET'])]
    public function log(): JsonResponse
    {
        $logFile = RadioStartCommand::logFile();

        if (!file_exists($logFile)) {
            return new JsonResponse(['lines' => []]);
        }

        // Read last 150 lines efficiently without loading the whole file
        $lines = $this->tailFile($logFile, 150);

        // Strip ANSI escape codes
        $lines = array_map(
            fn(string $l) => preg_replace('/\x1b\[[0-9;]*m/', '', $l),
            $lines
        );

        // Filter out http_client request/response noise
        $lines = array_filter(
            $lines,
            fn(string $l) => !preg_match('/\[info\] (Request|Response): "/', $l)
        );

        $response = new JsonResponse(['lines' => array_values($lines)]);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        return $response;
    }

    private function tailFile(string $path, int $n): array
    {
        $file  = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $total = $file->key();

        $start = max(0, $total - $n);
        $file->seek($start);

        $lines = [];
        while (!$file->eof()) {
            $line = $file->current();
            if ($line !== false && $line !== '') {
                $lines[] = rtrim((string) $line);
            }
            $file->next();
        }

        return $lines;
    }

    private function runVolumeCommand(string $value): JsonResponse
    {
        try {
            [$backend, $current] = $this->getVolumeDirectly();
            if ($current === null) {
                return new JsonResponse(['success' => false, 'message' => 'Could not reach any playback device'], 500);
            }

            $step   = 10;
            $target = match ($value) {
                'up'    => min(100, $current + $step),
                'down'  => max(0,   $current - $step),
                default => max(0, min(100, (int) $value)),
            };

            $this->setVolumeDirectly($backend, $target);

            return new JsonResponse(['success' => true, 'volume' => $target]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function fetchVolume(): array
    {
        try {
            [$backend, $volume] = $this->getVolumeDirectly();
            return ['volume' => $volume, 'backend' => $backend];
        } catch (\Throwable) {
            return ['volume' => null, 'backend' => null];
        }
    }

    private function getVolumeDirectly(): array
    {
        $method = $this->radioState->getState()['playback_method'] ?? 'sonos_upnp';

        if ($method === 'sonos_api') {
            try {
                $roomName = $this->sonos->getRoomName();
                if ($roomName && $this->sonosApi->discoverGroup($roomName)) {
                    $vol = $this->sonosApi->getGroupVolume();
                    if ($vol !== null) {
                        return ['sonos_api', $vol];
                    }
                }
            } catch (\Throwable) {}
        }

        if ($method === 'spotify') {
            try {
                $playback = $this->spotify->getCurrentPlayback();
                if (!empty($playback) && isset($playback['volume_percent'])) {
                    return ['spotify', $playback['volume_percent']];
                }
            } catch (\Throwable) {}
        }

        $vol = $this->sonos->getVolume();
        return ['sonos_upnp', $vol];
    }

    private function setVolumeDirectly(string $backend, int $target): void
    {
        match ($backend) {
            'sonos_api' => $this->sonosApi->setGroupVolume($target),
            'spotify'   => $this->spotify->setVolume($target),
            default     => $this->sonos->setVolume($target),
        };
    }

    // ── User management ──────────────────────────────────────────────────────

    #[Route('/api/user/{id}/name', name: 'app_admin_user_name', methods: ['POST'])]
    public function updateUserName(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->userRepository->find($id);
        if (!$user) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $name = trim($data['name'] ?? '');
        $user->setName($name !== '' ? $name : null);
        $em->flush();

        return new JsonResponse(['ok' => true, 'name' => $user->getDisplayName()]);
    }

    // ── Song requests ────────────────────────────────────────────────────────

    #[Route('/api/song-requests', name: 'app_admin_song_requests', methods: ['GET'])]
    public function songRequests(SongRequestRepository $repo): JsonResponse
    {
        $requests = $repo->findRecent(50);
        return new JsonResponse(array_map(fn($r) => [
            'id'          => $r->getId(),
            'title'       => $r->getTitle(),
            'artist'      => $r->getArtist(),
            'imageUrl'    => $r->getImageUrl(),
            'requestedBy' => $r->getRequestedBy(),
            'requestedAt' => $r->getRequestedAt()->getTimestamp(),
            'status'      => $r->getStatus(),
        ], $requests));
    }

    #[Route('/api/song-request/{id}/approve', name: 'app_admin_song_request_approve', methods: ['POST'])]
    public function approveSongRequest(int $id, SongRequestRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $request = $repo->find($id);
        if (!$request) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }
        $request->approve();
        $em->flush();
        return new JsonResponse(['ok' => true, 'status' => $request->getStatus()]);
    }

    #[Route('/api/song-request/{id}/reject', name: 'app_admin_song_request_reject', methods: ['POST'])]
    public function rejectSongRequest(int $id, SongRequestRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $request = $repo->find($id);
        if (!$request) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }
        $request->reject();
        $em->flush();
        return new JsonResponse(['ok' => true, 'status' => $request->getStatus()]);
    }

    // ── Playlist pool ────────────────────────────────────────────────────────

    #[Route('/api/playlists', name: 'app_admin_playlists', methods: ['GET'])]
    public function playlists(PlaylistRepository $repo): JsonResponse
    {
        return new JsonResponse(array_map(fn(Playlist $p) => [
            'id'        => $p->getId(),
            'spotifyId' => $p->getSpotifyId(),
            'label'     => $p->getLabel(),
            'active'    => $p->isActive(),
        ], $repo->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC'])));
    }

    #[Route('/api/playlists', name: 'app_admin_playlist_add', methods: ['POST'])]
    public function playlistAdd(Request $request, PlaylistRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $data      = json_decode($request->getContent(), true);
        $spotifyId = trim($data['spotifyId'] ?? '');
        $label     = trim($data['label'] ?? '');

        if ($spotifyId === '' || $label === '') {
            return new JsonResponse(['error' => 'spotifyId and label are required'], 400);
        }

        // Prevent duplicates
        if ($repo->findOneBy(['spotifyId' => $spotifyId])) {
            return new JsonResponse(['error' => 'Playlist already in pool'], 409);
        }

        $maxOrder = (int) $repo->createQueryBuilder('p')
            ->select('MAX(p.sortOrder)')
            ->getQuery()->getSingleScalarResult();

        $playlist = new Playlist($spotifyId, $label, $maxOrder + 1);
        $em->persist($playlist);
        $em->flush();

        return new JsonResponse([
            'id'        => $playlist->getId(),
            'spotifyId' => $playlist->getSpotifyId(),
            'label'     => $playlist->getLabel(),
            'active'    => $playlist->isActive(),
        ], 201);
    }

    #[Route('/api/playlists/{id}', name: 'app_admin_playlist_remove', methods: ['DELETE'])]
    public function playlistRemove(int $id, PlaylistRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $playlist = $repo->find($id);
        if (!$playlist) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        $em->remove($playlist);
        $em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/playlists/{id}/toggle', name: 'app_admin_playlist_toggle', methods: ['POST'])]
    public function playlistToggle(int $id, PlaylistRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $playlist = $repo->find($id);
        if (!$playlist) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        $playlist->setActive(!$playlist->isActive());
        $em->flush();

        return new JsonResponse(['ok' => true, 'active' => $playlist->isActive()]);
    }

    #[Route('/api/playlists/search', name: 'app_admin_playlist_search', methods: ['POST'])]
    public function playlistSearch(Request $request): JsonResponse
    {
        $data  = json_decode($request->getContent(), true);
        $query = trim($data['query'] ?? '');

        if ($query === '') {
            return new JsonResponse(['results' => []]);
        }

        try {
            $results = $this->spotify->searchPlaylists($query, 8);
            return new JsonResponse(['results' => $results]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage(), 'results' => []], 500);
        }
    }

    private function scanColleagueImages(): array
    {
        $dir = $this->kernel->getProjectDir() . '/public/images/colleagues';
        if (!is_dir($dir)) {
            return [];
        }

        $images = [];
        foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $images[] = $file->getFilename();
            }
        }
        sort($images);

        return $images;
    }
}
