<?php
namespace App\Controller;

use App\Entity\SonosToken;
use App\Entity\SpotifyToken;
use App\Repository\TrackRepository;
use App\Service\JiraService;
use App\Service\NewsService;
use App\Service\RadioStateService;
use App\Service\RemoteRadioService;
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
        private NewsService $newsService,
        private RemoteRadioService $remoteRadio,
        private string $jiraAlarmAccount,
        private string $jiraAlarmLabels,
        private string $projectDir,
    ) {}

    #[Route('/', name: 'app_radio', methods: ['GET'])]
    public function index(): Response
    {
        $soccerFile = $this->projectDir . '/var/soccer-dates.json';
        $soccerStart = '';
        $soccerEnd   = '';
        if (file_exists($soccerFile)) {
            $data = json_decode(file_get_contents($soccerFile), true);
            $soccerStart = $data['start'] ?? '';
            $soccerEnd   = $data['end'] ?? '';
        }

        return $this->render('radio/index.html.twig', [
            'station'      => 'SRS FM',
            'soccer_start' => $soccerStart,
            'soccer_end'   => $soccerEnd,
        ]);
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

    #[Route('/setup', name: 'setup', methods: ['GET'])]
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

        // If local radio is idle, check if remote radio is running
        $remoteState = null;
        if (($state['status'] ?? 'idle') === 'idle') {
            $remoteStatus = $this->remoteRadio->status();
            if (($remoteStatus['running'] ?? false) && ($remoteStatus['state']['status'] ?? '') === 'playing') {
                $remoteState = $remoteStatus['state'];
            }
        }

        // Use remote state if available, otherwise local
        $sourceState = $remoteState ?? $state;

        $isIdle = ($sourceState['status'] ?? 'idle') === 'idle';
        $track  = $isIdle ? '—' : ($sourceState['track_title']  ?? '—');
        $artist = $isIdle ? '—' : ($sourceState['track_artist'] ?? '—');

        $radioIsPlaying   = $sourceState['status'] === 'playing';
        $playback         = $radioIsPlaying ? ($this->spotifyService->getCurrentPlayback() ?? []) : [];
        $spotifyIsPlaying = $radioIsPlaying && ($playback['is_playing'] ?? false);

        // Primary source: state file written by radio:start when track begins
        $durationMs = (int) ($sourceState['track_duration_ms'] ?? 0);
        $progressMs = 0;
        if ($durationMs > 0 && isset($sourceState['track_started_at'])) {
            $progressMs = (int) ((time() - $sourceState['track_started_at']) * 1000);
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

        $response = new JsonResponse([
            'track'             => $track,
            'artist'            => $artist,
            'image'             => $sourceState['track_image'] ?? $playback['album_image'] ?? null,
            'dj_text'           => $isIdle ? null : ($sourceState['dj_text'] ?? null),
            'progress_ms'       => $progressMs,
            'duration_ms'       => $durationMs,
            'is_playing'        => $spotifyIsPlaying || $radioIsPlaying,
            'status'            => $sourceState['status'],
            'start_at'          => $sourceState['start_at'] ?? null,
            'dj_clip_url'       => $sourceState['dj_clip_url'] ?? null,
            'playback_method'   => $sourceState['playback_method'] ?? null,
            'next_track_title'  => $isIdle ? null : ($sourceState['next_track_title'] ?? null),
            'next_track_artist' => $isIdle ? null : ($sourceState['next_track_artist'] ?? null),
            'birthday_active'   => (bool) ($sourceState['birthday_active'] ?? false),
            'birthday_name'     => $sourceState['birthday_name'] ?? null,
            'birthday_picture'  => isset($sourceState['birthday_picture']) ? '/images/colleagues/' . $sourceState['birthday_picture'] : null,
            'alarm_active'      => (bool) ($sourceState['alarm_active'] ?? false),
            'alarm_key'         => $sourceState['alarm_key'] ?? null,
            'alarm_summary'     => $sourceState['alarm_summary'] ?? null,
        ]);

        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    #[Route('/api/dj-clip-done', methods: ['POST'])]
    public function djClipDone(): JsonResponse
    {
        $this->radioState->markDjClipDone();
        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/news-headlines', methods: ['GET'])]
    public function newsHeadlines(): JsonResponse
    {
        $headlines = $this->newsService->getHeadlines(20);
        return new JsonResponse($headlines);
    }
}
