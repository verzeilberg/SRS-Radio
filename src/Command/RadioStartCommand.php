<?php
namespace App\Command;

use App\DTO\DjContext;
use App\Entity\DjAnnouncement;
use App\Entity\Track;
use App\Repository\TrackRepository;
use App\Service\DjScriptService;
use App\Service\RadioStateService;
use App\Service\SonosApiService;
use App\Service\SonosService;
use App\Service\SpotifyService;
use App\Service\TextToSpeechService;
use App\Service\WeatherService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'radio:start', description: 'Start the autonomous radio station')]
class RadioStartCommand extends Command
{
    private string $playbackMethod = 'upnp';
    private bool   $running        = true;
    private bool   $skipCurrent    = false;

    // Tracks which time-event types have been played today (type => 'YYYY-MM-DD')
    private array $playedTimeEvents = [];

    private int $tracksSinceDj   = 0;
    private int $djEveryNTracks  = 2; // randomised each time

    // Built in constructor so WEATHER_HOUR can be injected: [hour, day-of-week, type]
    private array $timeEvents;

    public function __construct(
        private SpotifyService $spotify,
        private SonosService $sonos,
        private SonosApiService $sonosApi,
        private DjScriptService $djService,
        private TextToSpeechService $tts,
        private TrackRepository $trackRepository,
        private EntityManagerInterface $em,
        private RadioStateService $radioState,
        private WeatherService $weather,
        private string $projectDir,
        private string $spotifyDeviceName = 'PHPSD',
        private int $weatherHour = 12,
        private int $weatherMinute = 20,
    ) {
        parent::__construct();

        // Each entry: [hour, minute, day-of-week|null, type]
        $this->timeEvents = [
            [$this->weatherHour, $this->weatherMinute, null,  'weather'],
            [9,                  0,                    null,  'morning'],
            [12,                 0,                    null,  'lunch'],
            [14,                 0,                    null,  'afternoon'],
            [16,                 0,                    'Fri', 'friday_afternoon'],
            [17,                 0,                    null,  'end_of_day'],
        ];
    }

    public static function pidFile(): string
    {
        return '/var/www/srs-radio/var/radio.pid';
    }

    protected function configure(): void
    {
        $this
            ->addOption('start-at', null, InputOption::VALUE_OPTIONAL, 'Start time (HH:MM), e.g. 10:00. Omit to start immediately.')
            ->addOption('device',   null, InputOption::VALUE_OPTIONAL,  'Spotify device name to play on (see radio:devices). Omit to use Sonos.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('SRS FM — Autonomous Radio Station');

        $this->clearDjSounds();

        $deviceName = $input->getOption('device');
        if ($deviceName !== null) {
            $io->writeln(sprintf('<info>Spotify Connect modus:</info> %s', $deviceName));
            $deviceId = $this->waitForSpotifyDevice($io, $deviceName);
            $this->spotify->setDeviceId($deviceId);
            $this->playbackMethod = 'spotify_connect';
        } else {
            $this->connectSonos($io);
        }

        $startAt = $input->getOption('start-at');
        if ($startAt !== null) {
            $this->radioState->setWaiting($startAt);
            $this->waitUntil($startAt, $io);
        }

        $this->radioState->setPlaying();
        file_put_contents(self::pidFile(), getmypid());

        if (function_exists('pcntl_signal')) {
            $stop = function () use ($io): void {
                $io->writeln("\n<comment>Stopping...</comment>");
                $this->running = false;
            };
            pcntl_signal(SIGINT,  $stop);
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGUSR1, function () use ($io): void {
                $io->writeln("\n<comment>Skip...</comment>");
                $this->skipCurrent = true;
            });
        }

        // Pre-pick two tracks: first to play immediately, second to show as "next" from the start.
        $nextTrack     = $this->pickTrack($io);
        $nextNextTrack = $nextTrack ? $this->pickTrack($io, [$nextTrack['id']]) : null;
        $nextDjUrl     = null;
        $nextDjText    = null;
        $wasPreQueued  = false; // true when Sonos already started the next track via SetNextAVTransportURI

        while ($this->running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            $this->playTimeEventIfScheduled($io);

            $track = $nextTrack;

            if (!$track) {
                $io->warning('No tracks available. Retrying in 30s...');
                sleep(30);
                $nextTrack     = $this->pickTrack($io);
                $nextNextTrack = $nextTrack ? $this->pickTrack($io, [$nextTrack['id']]) : null;
                continue;
            }

            // Play pre-generated DJ intro — skip when Sonos already auto-started the next track.
            // state.next already shows $track from the previous iteration, which is correct:
            // the DJ is introducing the track that's about to play.
            if (!$wasPreQueued && $nextDjUrl !== null) {
                $io->writeln('<info>DJ:</info> ' . $nextDjText);
                if ($this->playbackMethod === 'spotify_connect') {
                    $this->playDjClipViaBrowser($nextDjUrl, $io);
                } else {
                    $djDurationMs = (int) ($this->tts->getDuration($nextDjUrl) * 1000);
                    $this->radioState->setTrack('DJ Sander', 'SRS FM', $djDurationMs);
                    try {
                        $this->sonos->playHttpClip($nextDjUrl);
                        for ($i = 0; $i < 120 && $this->running && !$this->skipCurrent; $i += 2) {
                            if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
                            if (!$this->sonos->isPlaying()) break;
                            sleep(2);
                        }
                        if ($this->skipCurrent) {
                            $this->sonos->stop();
                        } else {
                            $this->em->persist(new DjAnnouncement($nextDjText, $nextDjUrl, 'between_tracks'));
                            $this->em->flush();
                        }
                    } catch (\Throwable $e) {
                        $io->warning('DJ afspelen mislukt: ' . $e->getMessage());
                    }
                    $this->skipCurrent = false;
                }
                $this->tts->delete($nextDjUrl);
                $nextDjUrl  = null;
                $nextDjText = null;
            }

            if (!$this->running) {
                break;
            }

            $io->section(sprintf('Now playing: %s — %s', $track['title'], $track['artist']));

            if (!$wasPreQueued) {
                try {
                    $this->playTrack($track, $io);
                } catch (\Throwable $e) {
                    $io->error('Playback error: ' . $e->getMessage());
                    sleep(10);
                    $nextTrack     = $this->pickTrack($io);
                    $nextNextTrack = $nextTrack ? $this->pickTrack($io, [$nextTrack['id']]) : null;
                    $wasPreQueued  = false;
                    continue;
                }
            } else {
                $io->writeln('<comment>→ Sonos auto-transition</comment>');
            }
            $wasPreQueued = false;
            $this->radioState->setPlaybackMethod($this->playbackMethod);

            $this->radioState->setTrack(
                $track['title'],
                $track['artist'],
                $track['duration_ms'] ?? 0,
                $track['image'] ?? null,
            );

            // Immediately show the pre-known next track — no API call needed, no gap.
            if ($nextNextTrack) {
                $this->radioState->setNextTrack($nextNextTrack['title'], $nextNextTrack['artist']);
            } else {
                $this->radioState->clearNextTrack();
            }

            $this->em->persist(new Track($track['title'], $track['artist'], $track['id']));
            $this->em->flush();

            // Advance the queue: next-next becomes next, pick new next-next during playback.
            $nextTrack     = $nextNextTrack;
            $nextNextTrack = $nextTrack ? $this->pickTrack($io, [$nextTrack['id']]) : null;

            $this->tracksSinceDj++;
            if ($this->tracksSinceDj >= $this->djEveryNTracks && $nextTrack) {
                [$nextDjText, $nextDjUrl] = $this->pregenerateDjAnnouncement($nextTrack, $io);
                $this->tracksSinceDj  = 0;
                $this->djEveryNTracks = random_int(2, 3);
            }

            // Pass nextTrack only when no DJ is planned — then waitForTrackToEnd() will
            // pre-queue it on Sonos UPnP so the transition is seamless.
            $this->waitForTrackToEnd($io, $nextDjUrl === null ? $nextTrack : null, $wasPreQueued);

            if ($this->skipCurrent) {
                $wasPreQueued      = false; // always start next track explicitly after a skip
                $this->skipCurrent = false;
            }

            if (!$this->running) {
                break;
            }
        }

        if ($this->playbackMethod === 'spotify_connect') {
            $this->spotify->pause();
        } else {
            $this->sonos->stop();
        }
        $this->radioState->setStopped();
        $this->clearDjSounds();
        @unlink(self::pidFile());
        $io->success('Radio stopped.');

        return Command::SUCCESS;
    }

    private function playTrack(array $track, SymfonyStyle $io): void
    {
        if ($this->playbackMethod === 'spotify_connect') {
            $this->spotify->playTrack($track['uri']);
            $io->writeln('<comment>→ Spotify Connect</comment>');
            return;
        }

        if ($this->sonosApi->getGroupId()) {
            $io->writeln('<comment>→ Sonos API</comment>');
            $this->sonosApi->playSpotifyTrack($track['uri'], $track['title'], $track['artist']);
            $this->playbackMethod = 'sonos_api';
            return;
        }

        try {
            $this->sonos->play($track['id'], $track['title'], $track['artist']);
            $io->writeln('<comment>→ Sonos UPnP</comment>');
            $this->playbackMethod = 'upnp';
            return;
        } catch (\Throwable $e) {
            $io->writeln(sprintf('<comment>→ Sonos UPnP mislukt (%s) — probeert Spotify Connect op "%s"</comment>', $e->getMessage(), $this->spotifyDeviceName));
        }

        $deviceId = $this->waitForSpotifyDevice($io);

        $this->spotify->setDeviceId($deviceId);
        $this->spotify->playTrack($track['uri']);
        $io->writeln(sprintf('<comment>→ Spotify Connect (%s)</comment>', $this->spotifyDeviceName));
        $this->playbackMethod = 'spotify_connect';
    }

    private function pickTrack(SymfonyStyle $io, array $excludeIds = []): ?array
    {
        try {
            $topTracks = $this->spotify->getTopTracks('medium_term', 50);
        } catch (\Throwable $e) {
            $io->warning('Spotify top tracks ophalen mislukt: ' . $e->getMessage());
            return null;
        }

        if (empty($topTracks)) {
            return null;
        }

        $since      = new \DateTimeImmutable('-7 days');
        $recentIds  = $this->trackRepository->findSpotifyIdsPlayedSince($since);
        $allExclude = array_merge($recentIds, $excludeIds);
        $pool       = array_filter($topTracks, fn($t) => !in_array($t['id'], $allExclude, true));

        if (empty($pool)) {
            $io->note('All top tracks played in the last 7 days — resetting pool.');
            // Still exclude immediately-upcoming tracks to avoid back-to-back repeats.
            $pool = array_filter($topTracks, fn($t) => !in_array($t['id'], $excludeIds, true));
            if (empty($pool)) {
                $pool = $topTracks;
            }
        }

        return $pool[array_rand($pool)];
    }

    private function connectSonos(SymfonyStyle $io): void
    {
        $roomName = $this->sonos->getRoomName();

        $groupId = $this->sonosApi->discoverGroup($roomName);
        if ($groupId) {
            $io->writeln(sprintf('<info>Sonos verbonden via API:</info> %s (group %s)', $roomName, $groupId));
            return;
        }

        $io->writeln(sprintf('<info>Sonos verbonden via UPnP:</info> %s', $roomName));
    }

    private function waitUntil(string $time, SymfonyStyle $io): void
    {
        $tz = new \DateTimeZone('Europe/Amsterdam');
        $target = \DateTimeImmutable::createFromFormat('H:i', $time, $tz);
        $now = new \DateTimeImmutable('now', $tz);

        if ($now >= $target) {
            return;
        }

        $seconds = $target->getTimestamp() - $now->getTimestamp();
        $io->writeln(sprintf('Waiting until %s (%d minutes)...', $time, (int) ($seconds / 60)));

        while (true) {
            $remaining = $target->getTimestamp() - (new \DateTimeImmutable())->getTimestamp();
            if ($remaining <= 0) {
                break;
            }
            sleep(min(30, $remaining));
        }

        $io->writeln('Starting!');
    }

    private function waitForSpotifyDevice(SymfonyStyle $io, string $deviceName = '', int $maxAttempts = 12, int $delaySec = 10): string
    {
        $name = $deviceName ?: $this->spotifyDeviceName;

        for ($i = 1; $i <= $maxAttempts; $i++) {
            $deviceId = $this->spotify->findDeviceByName($name);
            if ($deviceId) {
                return $deviceId;
            }

            $io->writeln(sprintf(
                '<comment>Wacht op Spotify device "%s" (poging %d/%d)...</comment>',
                $name,
                $i,
                $maxAttempts,
            ));
            sleep($delaySec);
        }

        throw new \RuntimeException(sprintf(
            'Spotify Connect device "%s" niet gevonden na %d pogingen. '
            . 'Run "bin/console radio:devices" om beschikbare devices te zien.',
            $name,
            $maxAttempts,
        ));
    }

    /** Generate DJ text + TTS during track playback so it's ready with no gap. */
    private function pregenerateDjAnnouncement(array $track, SymfonyStyle $io): array
    {
        try {
            $io->writeln('<comment>DJ voorbereiden voor: ' . $track['title'] . '…</comment>');

            $djText = $this->djService->generate(new DjContext(
                station: 'SRS FM',
                track: $track['title'],
                artist: $track['artist'],
                mood: 'energetic',
                hour: (int) date('H'),
                previousTrack: $this->trackRepository->findPreviousTrack(),
                style: 'energy',
                type: 'between_tracks',
            ));

            $audioUrl = $this->tts->generate($djText);

            return [$djText, $audioUrl];
        } catch (\Throwable $e) {
            $io->warning('DJ pre-genereren mislukt: ' . $e->getMessage());
            return [null, null];
        }
    }

    private function playTimeEventIfScheduled(SymfonyStyle $io): void
    {
        $tz     = new \DateTimeZone('Europe/Amsterdam');
        $now    = new \DateTimeImmutable('now', $tz);
        $hour   = (int) $now->format('H');
        $minute = (int) $now->format('i');
        $today  = $now->format('Y-m-d');
        $dow    = $now->format('D');

        $type = null;
        foreach ($this->timeEvents as [$eventHour, $eventMinute, $eventDay, $eventType]) {
            if ($eventHour !== $hour) {
                continue;
            }
            if ($minute < $eventMinute) {
                continue; // scheduled minute hasn't arrived yet
            }
            if ($eventDay !== null && $eventDay !== $dow) {
                continue;
            }
            // Skip if already played today — allows multiple events in the same hour
            if (($this->playedTimeEvents[$eventType] ?? '') === $today) {
                continue;
            }
            $type = $eventType;
            break;
        }

        if ($type === null) {
            return;
        }

        $this->playedTimeEvents[$type] = $today;

        try {
            $weatherData = $type === 'weather' ? $this->weather->getCurrent() : null;

            $djText = $this->djService->generate(new DjContext(
                station: 'SRS FM',
                track: '',
                artist: '',
                mood: 'energetic',
                hour: $hour,
                type: $type,
                weather: $weatherData,
            ));

            $io->writeln(sprintf('<comment>[%s]</comment> <info>DJ:</info> %s', $type, $djText));

            $audioUrl = $this->tts->generate($djText);
            if ($this->playbackMethod === 'spotify_connect') {
                $this->playDjClipViaBrowser($audioUrl, $io);
            } else {
                $djDurationMs = (int) ($this->tts->getDuration($audioUrl) * 1000);
                $this->radioState->setTrack('DJ Sander', 'SRS FM', $djDurationMs);
                $this->sonos->playHttpClip($audioUrl);
                for ($i = 0; $i < 120 && $this->running && !$this->skipCurrent; $i += 2) {
                    if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
                    if (!$this->sonos->isPlaying()) break;
                    sleep(2);
                }
                if ($this->skipCurrent) {
                    $this->sonos->stop();
                    $this->skipCurrent = false;
                }
            }
            $this->tts->delete($audioUrl);

            $this->em->persist(new DjAnnouncement($djText, $audioUrl, $type));
            $this->em->flush();
        } catch (\Throwable $e) {
            $io->warning(sprintf('Time event [%s] mislukt: %s', $type, $e->getMessage()));
        }
    }

    private function clearDjSounds(): void
    {
        $dir = $this->projectDir . '/public/sounds/dj';
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*.mp3') as $file) {
            @unlink($file);
        }
    }

    private function playDjClipViaBrowser(string $url, SymfonyStyle $io): void
    {
        $duration = $this->tts->getDuration($url);
        $io->writeln(sprintf('<comment>DJ browser clip: %.1fs</comment>', $duration));

        $this->radioState->setTrack('DJ Sander', 'SRS FM', (int) ($duration * 1000));
        $this->radioState->setDjClip($url);

        // Wait for the clip duration so the next track doesn't start while DJ is speaking.
        // The browser plays it independently; we don't rely on a browser signal.
        $end = microtime(true) + $duration + 1.5;
        while (microtime(true) < $end && $this->running && !$this->skipCurrent) {
            if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
            sleep(1);
        }

        $this->radioState->clearDjClip();
        $this->skipCurrent = false;
    }

    private function waitForTrackToEnd(SymfonyStyle $io, ?array $nextTrack = null, bool &$preQueued = false): void
    {
        sleep(5);

        while ($this->running && !$this->skipCurrent) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            if ($this->skipCurrent) {
                break;
            }

            try {
                if ($this->sonosApi->getGroupId()) {
                    $playback = $this->sonosApi->getPlayback();
                    if (empty($playback) || !$playback['is_playing']) {
                        break;
                    }
                    $remaining = ($playback['duration_ms'] - $playback['progress_ms']) / 1000;
                } elseif ($this->playbackMethod === 'spotify_connect') {
                    $playback = $this->spotify->getCurrentPlayback();
                    if (empty($playback) || !$playback['is_playing']) {
                        break;
                    }
                    $remaining = ($playback['duration_ms'] - $playback['progress_ms']) / 1000;
                } else {
                    if (!$this->sonos->isPlaying()) {
                        break;
                    }
                    $pos = $this->sonos->getPositionInfo();
                    // duration=0 means metadata not yet loaded — keep waiting
                    if ($pos['duration'] === 0) {
                        sleep(2);
                        continue;
                    }
                    $remaining = max(0, $pos['duration'] - $pos['position']);

                    // Pre-queue next track on Sonos so it can buffer while current song finishes
                    if (!$preQueued && $nextTrack !== null && $remaining <= 30) {
                        try {
                            $this->sonos->setNextTrack($nextTrack['id'], $nextTrack['title'], $nextTrack['artist']);
                            $preQueued = true;
                            $io->writeln('<comment>→ Volgend nummer in wachtrij</comment>');
                        } catch (\Throwable) {}
                    }
                }

                if ($remaining <= 3) {
                    // When pre-queued: break immediately so Sonos auto-transitions while we do
                    // bookkeeping. Without pre-queue: sleep through the last 3 seconds normally.
                    if (!$preQueued) {
                        sleep(3);
                    }
                    break;
                }

                sleep(min(10, max(1, (int) $remaining - 5)));
            } catch (\Throwable $e) {
                $io->warning('Playback check mislukt: ' . $e->getMessage());
                sleep(10);
                break;
            }
        }
    }
}
