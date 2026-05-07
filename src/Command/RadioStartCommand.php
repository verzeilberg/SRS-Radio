<?php
namespace App\Command;

use App\DTO\DjContext;
use App\Entity\DjAnnouncement;
use App\Entity\Track;
use App\Repository\ColleagueRepository;
use App\Repository\DjAnnouncementRepository;
use App\Repository\TrackRepository;
use App\Service\DjScriptService;
use App\Service\RadioStateService;
use App\Service\SonosApiService;
use App\Service\SonosService;
use App\Service\SpotifyService;
use App\Service\TextToSpeechService;
use App\Service\NewsService;
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
    private string  $playbackMethod     = 'upnp';
    private bool    $running            = true;
    private bool    $skipCurrent        = false;
    private ?array  $currentTrack       = null;
    private bool    $useSonosForDjClips = false;
    private ?int    $lastSpotifyVolume  = null;
    private string  $activeDeviceName   = '';

    // Tracks which time-event types have been played today (type => 'YYYY-MM-DD')
    private array $playedTimeEvents = [];

    // Birthday announcements queued to play at the next song boundary
    // Each entry: ['name' => string, 'picture' => ?string, 'uri' => string]
    private array $pendingBirthdays = [];

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
        private DjAnnouncementRepository $djAnnouncementRepository,
        private EntityManagerInterface $em,
        private RadioStateService $radioState,
        private WeatherService $weather,
        private NewsService $news,
        private ColleagueRepository $colleagueRepository,
        private string $projectDir,
        private string $spotifyDeviceName = 'PHPSD',
        private int $weatherHour = 12,
        private int $weatherMinute = 20,
        private array $trackPools = [],
        private string $djClipIp = '',
    ) {
        parent::__construct();

        // Each entry: [hour, minute, day-of-week|null, type]
        $this->timeEvents = [
            [$this->weatherHour, $this->weatherMinute, null,  'weather'],
            [9,                  0,                    null,  'morning'],
            [10,                 0,                    null,  'news'],
            [11,                 0,                    null,  'birthday'],
            [12,                 0,                    null,  'news'],
            [12,                 0,                    null,  'lunch'],
            [14,                 0,                    null,  'news'],
            [14,                 0,                    null,  'afternoon'],
            [16,                 0,                    null,  'news'],
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

        // Refuse to start if another instance is already running.
        $pidFile = self::pidFile();
        if (file_exists($pidFile)) {
            $existingPid = (int) trim(file_get_contents($pidFile));
            if ($existingPid > 0 && posix_kill($existingPid, 0)) {
                $io->error(sprintf(
                    'Radio is already running (PID %d). Run "bin/console radio:stop" first.',
                    $existingPid,
                ));
                return Command::FAILURE;
            }
            // Stale PID file — previous run crashed without cleanup.
            @unlink($pidFile);
        }

        $this->clearDjSounds();

        $deviceName = $input->getOption('device');
        if ($deviceName !== null) {
            $this->activeDeviceName = $deviceName;
            $io->writeln(sprintf('<info>Spotify Connect modus:</info> %s', $deviceName));
            $deviceId = $this->waitForSpotifyDevice($io, $deviceName);
            $this->spotify->setDeviceId($deviceId);
            $this->playbackMethod = 'spotify_connect';

            // Route DJ clips through Sonos when the Spotify Connect device IS a Sonos speaker
            try {
                $sonosRoom = $this->sonos->getRoomName();
                if ($sonosRoom && strcasecmp(trim($deviceName), trim($sonosRoom)) === 0) {
                    $groupId = $this->sonosApi->discoverGroup($sonosRoom);
                    $this->useSonosForDjClips = true;
                    $io->writeln(sprintf('<info>DJ clips via Sonos:</info> %s%s', $sonosRoom, $groupId ? ' (API)' : ' (UPnP)'));
                } else {
                    // Device may be a different Sonos speaker — try Sonos API first, then UPnP topology
                    $groupId = $this->sonosApi->discoverGroup($deviceName);
                    if ($groupId) {
                        $this->useSonosForDjClips = true;
                        if ($ip = $this->sonosApi->getPlayerIp()) {
                            $this->sonos->setIp($ip);
                        }
                        $io->writeln(sprintf('<info>DJ clips via Sonos API:</info> %s', $deviceName));
                    } elseif ($ip = $this->sonos->findDeviceIp($deviceName)) {
                        $this->sonos->setIp($ip);
                        $this->useSonosForDjClips = true;
                        $io->writeln(sprintf('<info>DJ clips via Sonos UPnP:</info> %s (%s)', $deviceName, $ip));
                    }
                }
            } catch (\Throwable) {}

            // DJ_CLIP_IP fallback: runs independently so a missing/unreachable SONOS_IP doesn't block it
            if (!$this->useSonosForDjClips && $this->djClipIp !== '') {
                try {
                    if ($this->sonos->discoverDlnaDevice($this->djClipIp)) {
                        $this->useSonosForDjClips = true;
                        $io->writeln(sprintf('<info>DJ clips via DLNA:</info> %s (%s)', $deviceName, $this->djClipIp));
                    }
                } catch (\Throwable) {}
            }
        } else {
            $this->connectSonos($io);
            $this->useSonosForDjClips = true;
        }

        // Detect server URL using the DJ device IP so clips land on the right subnet.
        $djTargetIp = $this->sonosApi->getPlayerIp()
            ?: ($this->sonos->getIp() ?: ($this->djClipIp ?: ''));
        $this->autoDetectServerUrl($io, $djTargetIp);

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
        $nextDjType    = 'between_tracks';
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
                if (!$this->useSonosForDjClips) {
                    $this->playDjClipViaBrowser($nextDjUrl, $io);
                } else {
                    $djDurationMs = (int) ($this->tts->getDuration($nextDjUrl) * 1000);
                    $this->radioState->setTrack('DJ Sander', 'SRS FM', $djDurationMs);
                    try {
                        $this->playDjClipViaSonos($nextDjUrl, $io);
                        if (!$this->skipCurrent) {
                            $this->em->persist(new DjAnnouncement($nextDjText, $nextDjUrl, $nextDjType));
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
                $nextDjType = 'between_tracks';
            }

            if (!$this->running) {
                break;
            }

            // Play any birthday announcements queued by the 11:00 time event
            if (!empty($this->pendingBirthdays)) {
                $wasPreQueued = false; // don't continue a pre-queued track; birthdays come first
                foreach ($this->pendingBirthdays as $birthday) {
                    $this->playBirthdayAnnouncement($birthday, $io);
                }
                $this->pendingBirthdays = [];
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
            $wasPreQueued       = false;
            $this->currentTrack = $track;
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
            $djDue = $this->tracksSinceDj >= $this->djEveryNTracks && $nextTrack;
            if ($djDue) {
                $this->tracksSinceDj  = 0;
                $this->djEveryNTracks = random_int(2, 3);
            }

            // Pass nextTrack only when no DJ is planned — then waitForTrackToEnd() will
            // pre-queue it on Sonos UPnP so the transition is seamless.
            // When a DJ is due, pass a callback so pregeneration runs inside the wait loop
            // at the 90-second mark — giving the full track duration to generate instead of
            // blocking here right after the track started.
            $djCallback = $djDue ? function () use ($nextTrack, $io, &$nextDjText, &$nextDjUrl, &$nextDjType): void {
                [$nextDjText, $nextDjUrl, $nextDjType] = $this->pregenerateDjAnnouncement($nextTrack, $io);
            } : null;

            $this->waitForTrackToEnd($io, $nextDjUrl === null ? $nextTrack : null, $wasPreQueued, $djCallback);

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
            $name = $this->activeDeviceName ?: $this->spotifyDeviceName;
            for ($attempt = 0; $attempt < 30; $attempt++) {
                try {
                    $this->spotify->playTrack($track['uri']);
                    $io->writeln('<comment>→ Spotify Connect</comment>');
                    return;
                } catch (\RuntimeException $e) {
                    if (!str_contains($e->getMessage(), '404') && !str_contains($e->getMessage(), 'Device not found')) {
                        throw $e;
                    }
                    // Refresh device ID once on first failure in case it changed after reconnect
                    if ($attempt === 0) {
                        if ($id = $this->spotify->findDeviceByName($name)) {
                            $this->spotify->setDeviceId($id);
                        }
                    }
                    usleep(300_000);
                }
            }
            throw new \RuntimeException(sprintf('Spotify device "%s" unreachable after retries.', $name));
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
        $since     = new \DateTimeImmutable('-7 days');
        $recentIds = $this->trackRepository->findSpotifyIdsPlayedSince($since);
        $exclude   = array_merge($recentIds, $excludeIds);

        // Shuffle pools so every era gets equal rotation over time
        $pools = $this->trackPools;
        shuffle($pools);

        // First pass: pick from a pool that still has non-recent tracks
        foreach ($pools as $pool) {
            try {
                $playlistId = $this->resolvePoolId($pool, $io);
                if (!$playlistId) {
                    continue;
                }

                $tracks   = $this->spotify->getPlaylistTracks($playlistId);
                $eligible = array_values(array_filter($tracks, fn($t) => !in_array($t['id'], $exclude, true)));

                if (!empty($eligible)) {
                    $track = $eligible[array_rand($eligible)];
                    $io->writeln(sprintf('<comment>Pool: %s</comment>', $pool['label']));
                    return $track;
                }
            } catch (\Throwable $e) {
                $io->warning(sprintf('Pool "%s" ophalen mislukt: %s', $pool['label'], $e->getMessage()));
            }
        }

        // All pools exhausted of non-recent tracks — reset the 7-day filter, keep only immediate excludes
        $io->note('Alle pools recent gespeeld — 7-dagenfilter resetten.');
        foreach ($pools as $pool) {
            try {
                $playlistId = $this->resolvePoolId($pool);
                if (!$playlistId) {
                    continue;
                }

                $tracks   = $this->spotify->getPlaylistTracks($playlistId);
                $eligible = array_values(array_filter($tracks, fn($t) => !in_array($t['id'], $excludeIds, true)));

                if (!empty($eligible)) {
                    return $eligible[array_rand($eligible)];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /** Resolve a playlist ID from a pool definition. Supports 'id' (direct) and 'name' (search). */
    private function resolvePoolId(array $pool, ?SymfonyStyle $io = null): ?string
    {
        if (!empty($pool['id'])) {
            return $pool['id'];
        }

        if (!empty($pool['name'])) {
            $id = $this->spotify->findPlaylistByName($pool['name']);
            if (!$id && $io) {
                $io->warning(sprintf('Playlist "%s" niet gevonden.', $pool['label']));
            }
            return $id;
        }

        return null;
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

            $type = random_int(1, 3) === 1 ? 'song_fact' : 'between_tracks';

            $djText = $this->djService->generate(new DjContext(
                station: 'SRS FM',
                track: $track['title'],
                artist: $track['artist'],
                mood: 'energetic',
                hour: (int) date('H'),
                previousTrack: $this->trackRepository->findPreviousTrack(),
                style: 'energy',
                type: $type,
                recentTexts: $this->djAnnouncementRepository->findRecentTexts($type, 10),
            ));

            $audioUrl = $this->tts->generate($djText);

            return [$djText, $audioUrl, $type];
        } catch (\Throwable $e) {
            $io->warning('DJ pre-genereren mislukt: ' . $e->getMessage());
            return [null, null, 'between_tracks'];
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

        $type    = null;
        $typeKey = null;
        foreach ($this->timeEvents as [$eventHour, $eventMinute, $eventDay, $eventType]) {
            if ($eventHour !== $hour) {
                continue;
            }
            if ($minute < $eventMinute) {
                continue;
            }
            if ($eventDay !== null && $eventDay !== $dow) {
                continue;
            }
            // Track by type@hour so the same type (e.g. 'news') can fire at multiple hours per day.
            $key = $eventType . '@' . $eventHour;
            if (($this->playedTimeEvents[$key] ?? '') === $today) {
                continue;
            }
            $type    = $eventType;
            $typeKey = $key;
            break;
        }

        if ($type === null) {
            return;
        }

        $this->playedTimeEvents[$typeKey] = $today;

        if ($type === 'birthday') {
            $this->queueBirthdayAnnouncements($io);
            return;
        }

        try {
            $weatherData = $type === 'weather' ? $this->weather->getCurrent() : null;
            $headlines   = $type === 'news' ? $this->news->getHeadlines(3) : null;

            $djText = $this->djService->generate(new DjContext(
                station: 'SRS FM',
                track: '',
                artist: '',
                mood: 'energetic',
                hour: $hour,
                type: $type,
                weather: $weatherData,
                headlines: $headlines,
                recentTexts: $this->djAnnouncementRepository->findRecentTexts($type, 10),
            ));

            $io->writeln(sprintf('<comment>[%s]</comment> <info>DJ:</info> %s', $type, $djText));

            $audioUrl = $this->tts->generate($djText);
            if (!$this->useSonosForDjClips) {
                $this->playDjClipViaBrowser($audioUrl, $io);
            } else {
                $djDurationMs = (int) ($this->tts->getDuration($audioUrl) * 1000);
                $this->radioState->setTrack('DJ Sander', 'SRS FM', $djDurationMs);
                $this->playDjClipViaSonos($audioUrl, $io);
                $this->skipCurrent = false;
            }
            $this->tts->delete($audioUrl);

            $this->em->persist(new DjAnnouncement($djText, $audioUrl, $type));
            $this->em->flush();
        } catch (\Throwable $e) {
            $io->warning(sprintf('Time event [%s] mislukt: %s', $type, $e->getMessage()));
        }
    }

    /** Called at 11:00 — searches Spotify and stores birthdays to play at the next song boundary. */
    private function queueBirthdayAnnouncements(SymfonyStyle $io): void
    {
        $colleagues = $this->colleagueRepository->findTodaysBirthdays();
        if (empty($colleagues)) {
            return;
        }

        $fallbackUri = 'spotify:track:7AwQUXHJnDnEeeow7dfLGi';

        foreach ($colleagues as $colleague) {
            $searchTitle = $colleague->getName() . ', Dit is je verjaardag';
            try {
                $uri = $this->spotify->searchTrackByTitle($searchTitle) ?? $fallbackUri;
            } catch (\Throwable) {
                $uri = $fallbackUri;
            }
            $this->pendingBirthdays[] = [
                'name'    => $colleague->getName(),
                'picture' => $colleague->getPicture(),
                'uri'     => $uri,
            ];
            $io->writeln(sprintf('<comment>[birthday] Queued for next song boundary: %s → %s</comment>',
                $colleague->getName(), $uri));
        }
    }

    /** Plays the TTS announcement + Spotify birthday song for one colleague. */
    private function playBirthdayAnnouncement(array $birthday, SymfonyStyle $io): void
    {
        $name    = $birthday['name'];
        $picture = $birthday['picture'];
        $uri     = $birthday['uri'];

        try {
            $io->writeln(sprintf('<comment>[birthday]</comment> <info>%s</info>', $name));

            $djText = $this->djService->generate(new DjContext(
                station: 'SRS FM',
                track: '',
                artist: '',
                mood: 'festive',
                hour: (int) date('H'),
                type: 'birthday',
                birthdayColleague: $name,
                recentTexts: $this->djAnnouncementRepository->findRecentTexts('birthday', 5),
            ));
            $io->writeln('<info>DJ:</info> ' . $djText);

            $audioUrl = $this->tts->generate($djText);
            if (!$this->useSonosForDjClips) {
                $this->playDjClipViaBrowser($audioUrl, $io);
            } else {
                $djDurationMs = (int) ($this->tts->getDuration($audioUrl) * 1000);
                $this->radioState->setTrack('DJ Sander', 'SRS FM', $djDurationMs);
                $this->playDjClipViaSonos($audioUrl, $io);
                $this->skipCurrent = false;
            }
            $this->tts->delete($audioUrl);

            $this->em->persist(new DjAnnouncement($djText, $audioUrl, 'birthday'));
            $this->em->flush();

            $this->playBirthdaySong($uri, $name, $picture, $io);

        } catch (\Throwable $e) {
            $io->warning(sprintf('Birthday [%s] failed: %s', $name, $e->getMessage()));
        }
    }

    private function playBirthdaySong(string $uri, string $name, ?string $picture, SymfonyStyle $io): void
    {
        // At the song boundary the Sonos player is idle, so loadStreamUrl plays immediately.
        // For UPnP-only setups, stop first so Spotify can take over.
        if ($this->playbackMethod === 'upnp') {
            try { $this->sonos->stop(); } catch (\Throwable) {}
            sleep(1);
        }

        if ($this->sonosApi->getGroupId()) {
            $this->sonosApi->playSpotifyTrack($uri, 'Happy Birthday ' . $name, 'SRS FM');
        } else {
            $this->spotify->playTrack($uri);
        }

        $this->radioState->setTrack('Happy Birthday', $name, 0);
        $this->radioState->setBirthday($name, $picture);
        $io->writeln(sprintf('<comment>[birthday] Playing song for %s...</comment>', $name));

        // Wait for the song to finish
        sleep(5);
        while ($this->running) {
            if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
            try {
                if ($this->sonosApi->getGroupId()) {
                    $playback  = $this->sonosApi->getPlayback();
                    if (empty($playback) || !$playback['is_playing']) break;
                    $remaining = ($playback['duration_ms'] - $playback['progress_ms']) / 1000;
                } else {
                    $playback  = $this->spotify->getCurrentPlayback();
                    if (empty($playback) || !$playback['is_playing']) break;
                    $remaining = ($playback['duration_ms'] - $playback['progress_ms']) / 1000;
                }
                if ($remaining <= 3) break;
                sleep(min(10, max(1, (int) $remaining - 3)));
            } catch (\Throwable) {
                break;
            }
        }

        $this->radioState->clearBirthday();

        // Signal the main loop to re-pick rather than resume a pre-queued track
        $this->skipCurrent = true;
    }

    private function playDjClipViaSonos(string $url, SymfonyStyle $io): void
    {
        if ($this->sonosApi->getGroupId()) {
            // Sonos Cloud API: overlays the clip on the active session without interrupting
            // Spotify Connect. Pass the clip volume directly so we don't touch group volume.
            $groupVol = $this->sonosApi->getGroupVolume() ?? 40;
            $clipVol  = min(100, $groupVol + 10);
            $io->writeln(sprintf('<comment>DJ clip via Sonos API (vol %d → clip %d): %s</comment>', $groupVol, $clipVol, $url));
            $ok = $this->sonosApi->playAudioClip($url, $clipVol);
            $io->writeln($ok ? '<comment>audioClip OK</comment>' : '<error>audioClip FAILED</error>');

            $duration = $this->tts->getDuration($url);
            $io->writeln(sprintf('<comment>Waiting %.1fs for clip to finish</comment>', $duration));
            $end = microtime(true) + $duration + 1.0;
            while (microtime(true) < $end && $this->running && !$this->skipCurrent) {
                if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
                usleep(300_000);
            }
            return;
        }

        $io->writeln(sprintf('<comment>DJ clip via DLNA/UPnP: %s</comment>', $url));

        // Non-Sonos device (Samsung soundbar via DLNA/UPnP): take over AVTransport.
        $origVol = $this->boostVolume();
        try {
            $this->sonos->playHttpClip($url);
            for ($ms = 0; $ms < 120_000 && $this->running && !$this->skipCurrent; $ms += 300) {
                if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
                if (!$this->sonos->isPlaying()) {
                    break;
                }
                usleep(300_000);
            }
            if ($this->skipCurrent) {
                $this->sonos->stop();
            }
        } finally {
            $this->restoreVolume($origVol);
        }
    }

    private function boostVolume(int $boost = 10): ?int
    {
        try {
            // Sonos speaker (native or Spotify Connect): boost via group API so the clip is audible.
            if ($this->sonosApi->getGroupId()) {
                $vol = $this->sonosApi->getGroupVolume();
                if ($vol !== null) {
                    $this->sonosApi->setGroupVolume(min(100, $vol + $boost));
                    return $vol;
                }
            }

            // Non-Sonos device via Spotify Connect (e.g. Samsung soundbar): the music runs through
            // Spotify's software volume while DJ clips use the UPnP hardware channel. Sync them so
            // the clip plays at the same level as the music.
            if ($this->playbackMethod === 'spotify_connect') {
                $deviceVol = $this->sonos->getVolume();
                $target    = min(100, ($this->lastSpotifyVolume ?? $deviceVol) + $boost);
                $this->sonos->setVolume($target);
                return $deviceVol;
            }

            // Sonos UPnP direct fallback.
            $vol = $this->sonos->getVolume();
            $this->sonos->setVolume(min(100, $vol + $boost));
            return $vol;
        } catch (\Throwable) {}

        return null;
    }

    private function restoreVolume(?int $original): void
    {
        if ($original === null) {
            return;
        }
        try {
            if ($this->sonosApi->getGroupId()) {
                $this->sonosApi->setGroupVolume($original);
            } else {
                $this->sonos->setVolume($original);
            }
        } catch (\Throwable) {}
    }

    private function handleJiraAlarm(SymfonyStyle $io): void
    {
        $alarmFile = $this->projectDir . '/var/jira-alarm.json';
        if (!file_exists($alarmFile)) {
            return;
        }

        $alarm = json_decode(file_get_contents($alarmFile), true) ?? [];
        @unlink($alarmFile);

        $alarmUrl = $alarm['url'] ?? '';
        if (!$alarmUrl) {
            return;
        }

        $io->warning(sprintf('[ALARM] %s — %s', $alarm['key'] ?? '?', $alarm['summary'] ?? '?'));

        $resumePosition = 0;

        // Save playback position and stop music
        if ($this->playbackMethod === 'upnp') {
            try {
                $pos            = $this->sonos->getPositionInfo();
                $resumePosition = $pos['position'];
            } catch (\Throwable) {}
            try { $this->sonos->stop(); } catch (\Throwable) {}
        } elseif ($this->playbackMethod === 'sonos_api') {
            try {
                $playback       = $this->sonosApi->getPlayback();
                $resumePosition = (int) (($playback['progress_ms'] ?? 0) / 1000);
            } catch (\Throwable) {}
            try { $this->sonos->stop(); } catch (\Throwable) {}
        } elseif ($this->playbackMethod === 'spotify_connect') {
            try { $this->spotify->pause(); } catch (\Throwable) {}
        }

        // Play siren at boosted volume
        $debugMsg = sprintf('[ALARM] useSonos=%s groupId=%s url=%s', $this->useSonosForDjClips ? 'yes' : 'no', $this->sonosApi->getGroupId() ?? 'null', $alarmUrl);
        $io->writeln('<comment>' . $debugMsg . '</comment>');
        file_put_contents($this->projectDir . '/var/alarm-debug.log', date('H:i:s') . ' ' . $debugMsg . "\n", FILE_APPEND);
        $origVol = $this->boostVolume(15);
        try {
            if (!$this->useSonosForDjClips) {
                $alarmPath = $this->projectDir . '/public' . parse_url($alarmUrl, PHP_URL_PATH);
                $duration  = $this->tts->getFileDuration($alarmPath);
                $this->radioState->setDjClip($alarmUrl);
                $end = microtime(true) + $duration + 1.5;
                while (microtime(true) < $end && $this->running) {
                    if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
                    sleep(1);
                }
                $this->radioState->clearDjClip();
            } elseif ($this->sonosApi->getGroupId()) {
                $alarmPath       = $this->projectDir . '/public' . parse_url($alarmUrl, PHP_URL_PATH);
                $reachableAlarm  = rtrim($this->tts->getServerBaseUrl(), '/') . parse_url($alarmUrl, PHP_URL_PATH);
                $groupVol = $this->sonosApi->getGroupVolume() ?? 40;
                $clipVol  = min(100, $groupVol + 15);
                $io->writeln(sprintf('<comment>[ALARM] Sonos API clip: %s (vol %d)</comment>', $reachableAlarm, $clipVol));
                $ok = $this->sonosApi->playAudioClip($reachableAlarm, $clipVol);
                $result = $ok ? 'audioClip OK' : 'audioClip FAILED';
                $io->writeln($ok ? "<comment>[ALARM] $result</comment>" : "<error>[ALARM] $result</error>");
                file_put_contents($this->projectDir . '/var/alarm-debug.log', date('H:i:s') . ' ' . $result . "\n", FILE_APPEND);
                $duration  = $this->tts->getFileDuration($alarmPath);
                $end = microtime(true) + $duration + 1.5;
                while (microtime(true) < $end && $this->running) {
                    if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
                    usleep(300_000);
                }
            } else {
                $this->sonos->playHttpClip($alarmUrl);
                $this->sonos->waitForClipToEnd(120);
            }
        } catch (\Throwable $e) {
            $io->warning('Alarm playback failed: ' . $e->getMessage());
        } finally {
            $this->restoreVolume($origVol);
        }

        // Resume track from saved position
        if ($this->currentTrack === null) {
            return;
        }

        try {
            if ($this->playbackMethod === 'upnp') {
                $this->sonos->play($this->currentTrack['id'], $this->currentTrack['title'], $this->currentTrack['artist']);
                if ($resumePosition > 5) {
                    sleep(2);
                    $this->sonos->seek($resumePosition);
                }
            } elseif ($this->playbackMethod === 'sonos_api') {
                $this->sonosApi->playSpotifyTrack($this->currentTrack['uri'], $this->currentTrack['title'], $this->currentTrack['artist']);
            } elseif ($this->playbackMethod === 'spotify_connect') {
                $this->spotify->playTrack($this->currentTrack['uri']);
            }
            $io->writeln('<info>Radio resumed after alarm.</info>');
        } catch (\Throwable $e) {
            $io->warning('Resume after alarm failed: ' . $e->getMessage());
        }
    }

    private function autoDetectServerUrl(SymfonyStyle $io, string $targetIp = ''): void
    {
        // Route via the target device IP so we get the local IP on the same subnet.
        // Falls back to the internet-routing IP if no target is known.
        $target = $targetIp ?: '8.8.8.8';
        $lanIp  = trim((string) shell_exec(
            'ip route get ' . escapeshellarg($target) . ' 2>/dev/null | grep -oP \'src \K\S+\' | head -1'
        ));

        if (!$lanIp || !filter_var($lanIp, FILTER_VALIDATE_IP)) {
            return;
        }

        $current = $this->tts->getServerBaseUrl();
        $port    = parse_url($current, PHP_URL_PORT) ?? 8080;
        $scheme  = parse_url($current, PHP_URL_SCHEME) ?? 'http';
        $new     = "{$scheme}://{$lanIp}:{$port}";

        if ($new !== $current) {
            $this->tts->setServerBaseUrl($new);
            $io->writeln(sprintf('<info>DJ server URL:</info> %s', $new));
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

    private function waitForTrackToEnd(SymfonyStyle $io, ?array $nextTrack = null, bool &$preQueued = false, ?callable $djCallback = null): void
    {
        sleep(5);

        $djCallbackFired = false;

        while ($this->running && !$this->skipCurrent) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            if ($this->skipCurrent) {
                break;
            }

            if (file_exists($this->projectDir . '/var/jira-alarm.json')) {
                $this->handleJiraAlarm($io);
                $preQueued = false; // siren interrupted the pre-queue, start next track explicitly
                sleep(3);          // give Sonos a moment to report playing state
            }

            try {
                if ($this->playbackMethod === 'spotify_connect') {
                    $playback = $this->spotify->getCurrentPlayback();
                    if (empty($playback) || !$playback['is_playing']) {
                        break;
                    }
                    if (isset($playback['volume_percent'])) {
                        $this->lastSpotifyVolume = $playback['volume_percent'];
                    }
                    $remaining = ($playback['duration_ms'] - $playback['progress_ms']) / 1000;
                } elseif ($this->sonosApi->getGroupId()) {
                    $playback = $this->sonosApi->getPlayback();
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

                // Fire DJ pregeneration at the 90-second mark so it runs during the track
                // rather than blocking before waitForTrackToEnd starts.
                if (!$djCallbackFired && $djCallback !== null && $remaining <= 90) {
                    $djCallbackFired = true;
                    ($djCallback)();
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

        // Track ended before the 90s mark (very short song or early break) — generate now
        if (!$djCallbackFired && $djCallback !== null) {
            ($djCallback)();
        }
    }
}
