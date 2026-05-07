<?php
namespace App\Controller;

use App\Entity\SonosToken;
use App\Entity\SpotifyToken;
use App\Repository\TrackRepository;
use App\Service\JiraService;
use App\Service\RadioStateService;
use App\Service\SonosService;
use App\Service\SpotifyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RadioController extends AbstractController
{
    public function __construct(
        private SpotifyService $spotifyService,
        private SonosService $sonosService,
        private TrackRepository $trackRepository,
        private RadioStateService $radioState,
        private EntityManagerInterface $em,
        private JiraService $jiraService,
        private string $jiraAlarmAccount,
        private string $jiraAlarmLabels,
        private string $projectDir,
    ) {}

    #[Route('/', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('radio/index.html.twig', ['station' => 'SRS FM']);
    }

    #[Route('/api/jira-tickets', methods: ['GET'])]
    public function jiraTickets(): JsonResponse
    {
        $stateFile = $this->projectDir . '/var/jira-state.json';

        if (file_exists($stateFile) && (time() - filemtime($stateFile)) < 120) {
            $data = json_decode(file_get_contents($stateFile), true);
            return new JsonResponse($data['tickets'] ?? []);
        }

        // Fallback when jira:monitor is not running
        try {
            $labels  = array_values(array_filter(array_map('trim', explode(',', $this->jiraAlarmLabels))));
            $tickets = $this->jiraService->getHighestPriorityTickets($labels, $this->jiraAlarmAccount);
            return new JsonResponse(array_values($tickets));
        } catch (\Throwable) {
            return new JsonResponse([]);
        }
    }

    #[Route('/setup', methods: ['GET'])]
    public function setup(): Response
    {
        $spotifyToken = $this->em->getRepository(SpotifyToken::class)->findOneBy([]);
        $sonosToken   = $this->em->getRepository(SonosToken::class)->findOneBy([]);

        return $this->render('radio/setup.html.twig', [
            'spotify_connected' => $spotifyToken !== null && !$spotifyToken->isExpired(),
            'sonos_connected'   => $sonosToken   !== null && !$sonosToken->isExpired(),
        ]);
    }

    #[Route('/api/now-playing', methods: ['GET'])]
    public function nowPlaying(): JsonResponse
    {
        $state  = $this->radioState->getState();
        $latest = $this->trackRepository->findLatest();

        $isIdle = ($state['status'] ?? 'idle') === 'idle';
        $track  = $isIdle ? '—' : ($state['track_title']  ?? $latest?->getTitle()  ?? '—');
        $artist = $isIdle ? '—' : ($state['track_artist'] ?? $latest?->getArtist() ?? '—');

        $radioIsPlaying   = $state['status'] === 'playing';
        $playback         = $radioIsPlaying ? ($this->spotifyService->getCurrentPlayback() ?? []) : [];
        $spotifyIsPlaying = $radioIsPlaying && ($playback['is_playing'] ?? false);

        // Primary source: state file written by radio:start when track begins
        $durationMs = (int) ($state['track_duration_ms'] ?? 0);
        $progressMs = 0;
        if ($durationMs > 0 && isset($state['track_started_at'])) {
            $progressMs = (int) ((time() - $state['track_started_at']) * 1000);
            $progressMs = min($progressMs, $durationMs);
        }

        // Fallback 1: Spotify Connect playback API (accurate when using Spotify Connect)
        if ($durationMs === 0) {
            $progressMs = $playback['progress_ms'] ?? 0;
            $durationMs = $playback['duration_ms'] ?? 0;
        }

        // Fallback 2: Sonos UPnP GetPositionInfo (works for HTTP clips, not Spotify streams)
        if ($durationMs === 0 && $radioIsPlaying) {
            try {
                $pos = $this->sonosService->getPositionInfo();
                if ($pos['duration'] > 0) {
                    $durationMs = (int) ($pos['duration'] * 1000);
                    $progressMs = (int) ($pos['position'] * 1000);
                }
            } catch (\Throwable) {}
        }

        return new JsonResponse([
            'track'             => $track,
            'artist'            => $artist,
            'image'             => $state['track_image'] ?? $playback['album_image'] ?? null,
            'dj_text'           => $latest?->getDjText() ?? '…',
            'progress_ms'       => $progressMs,
            'duration_ms'       => $durationMs,
            'is_playing'        => $spotifyIsPlaying || $radioIsPlaying,
            'status'            => $state['status'],
            'start_at'          => $state['start_at'] ?? null,
            'dj_clip_url'       => $state['dj_clip_url'] ?? null,
            'playback_method'   => $state['playback_method'] ?? null,
            'next_track_title'  => $isIdle ? null : ($state['next_track_title'] ?? null),
            'next_track_artist' => $isIdle ? null : ($state['next_track_artist'] ?? null),
            'birthday_active'   => (bool) ($state['birthday_active'] ?? false),
            'birthday_name'     => $state['birthday_name'] ?? null,
            'birthday_picture'  => isset($state['birthday_picture']) ? '/images/colleagues/' . $state['birthday_picture'] : null,
        ]);
    }

    #[Route('/api/dj-clip-done', methods: ['POST'])]
    public function djClipDone(): JsonResponse
    {
        $this->radioState->markDjClipDone();
        return new JsonResponse(['ok' => true]);
    }
}
