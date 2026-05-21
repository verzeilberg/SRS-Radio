<?php
namespace App\Command;

use App\DTO\DjContext;
use App\Repository\ColleagueRepository;
use App\Service\DjScriptService;
use App\Service\RadioStateService;
use App\Service\SonosApiService;
use App\Service\SonosService;
use App\Service\SpotifyService;
use App\Service\TextToSpeechService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'radio:test-birthday', description: 'Test the birthday announcement and popup for a colleague')]
class TestBirthdayCommand extends Command
{
    private bool $running = true;

    public function __construct(
        private ColleagueRepository $colleagueRepository,
        private DjScriptService $djService,
        private SpotifyService $spotify,
        private RadioStateService $radioState,
        private TextToSpeechService $tts,
        private SonosApiService $sonosApi,
        private SonosService $sonos,
        private string $spotifyDeviceName = 'PHPSD',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'Colleague name (partial match). Omit to use first colleague in DB.')
            ->addOption('no-audio', null, InputOption::VALUE_NONE, 'Skip audio playback — only show the popup and print the DJ text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT,  function () { $this->running = false; });
            pcntl_signal(SIGTERM, function () { $this->running = false; });
        }

        // ── Resolve colleague ──────────────────────────────────────────────
        $nameArg = $input->getArgument('name');
        $name    = null;
        $picture = null;

        if ($nameArg) {
            foreach ($this->colleagueRepository->findBy([], ['name' => 'ASC']) as $c) {
                if (stripos($c->getName(), $nameArg) !== false) {
                    $name    = $c->getName();
                    $picture = $c->getPicture();
                    $io->writeln(sprintf('<info>Colleague found: %s (photo: %s)</info>', $name, $picture ?? 'none'));
                    break;
                }
            }
            if (!$name) {
                $name = $nameArg;
                $io->writeln(sprintf('<comment>"%s" not in DB — using as literal name (no photo).</comment>', $name));
            }
        } else {
            $all = $this->colleagueRepository->findBy([], ['name' => 'ASC']);
            if (empty($all)) {
                $io->error('No name given and no colleagues in the database. Usage: radio:test-birthday "Jan de Vries"');
                return Command::FAILURE;
            }
            $name    = $all[0]->getName();
            $picture = $all[0]->getPicture();
            $io->writeln(sprintf('<info>Using first colleague: %s</info>', $name));
        }

        $noAudio = $input->getOption('no-audio');

        // ── Discover Sonos — API first, UPnP fallback (mirrors radio:start) ─
        $hasSonosApi = false;
        $hasUpnp     = false;
        if (!$noAudio) {
            try {
                $sonosRoomName = $this->sonos->getRoomName() ?: $this->spotifyDeviceName;
                $groupId = $this->sonosApi->discoverGroup($sonosRoomName);
                $hasSonosApi = (bool) $groupId;
                if ($hasSonosApi) {
                    $io->writeln(sprintf('<info>Sonos API: %s</info>', $sonosRoomName));
                }
            } catch (\Throwable) {}

            if (!$hasSonosApi) {
                try {
                    $this->sonos->isPlaying(); // probes SONOS_IP:1400
                    $hasUpnp = true;
                    $io->writeln('<info>Sonos UPnP (fallback)</info>');
                } catch (\Throwable $e) {
                    $io->error('Sonos niet bereikbaar: ' . $e->getMessage());
                    return Command::FAILURE;
                }
            }
        }

        // ── Generate DJ announcement ───────────────────────────────────────
        $io->section('Generating DJ announcement via Groq');
        $djText = null;
        try {
            $djText = $this->djService->generate(new DjContext(
                station: 'SRS FM',
                track: '',
                artist: '',
                mood: 'festive',
                hour: (int) date('H'),
                type: 'birthday',
                birthdayColleague: $name,
                recentTexts: [],
            ));
            $io->writeln('<comment>DJ:</comment> ' . $djText);
        } catch (\Throwable $e) {
            $io->warning('DJ text generation failed: ' . $e->getMessage());
        }

        // ── Play TTS announcement ──────────────────────────────────────────
        if (!$noAudio && $djText) {
            $io->section('Playing TTS announcement');
            try {
                $audioUrl = $this->tts->generate($djText);
                $duration = $this->tts->getDuration($audioUrl);
                $io->writeln(sprintf('<comment>Clip: %s (%.1fs)</comment>', $audioUrl, $duration));

                if ($hasSonosApi) {
                    // Overlay clip — does not interrupt current playback
                    $groupVol = $this->sonosApi->getGroupVolume() ?? 40;
                    $clipVol  = min(100, $groupVol + 10);
                    $this->sonosApi->playAudioClip($audioUrl, $clipVol);

                    $end = microtime(true) + $duration + 1.0;
                    while ($this->running && microtime(true) < $end) {
                        if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
                        usleep(300_000);
                    }
                } else {
                    // UPnP: takes over AVTransport
                    $this->sonos->playHttpClip($audioUrl);
                    $this->sonos->waitForClipToEnd((int) ($duration + 10));
                }
                $this->tts->delete($audioUrl);
            } catch (\Throwable $e) {
                $io->warning('TTS playback failed: ' . $e->getMessage());
            }
        }

        // ── Search Spotify for birthday song ───────────────────────────────
        $io->section('Searching Spotify for personalised birthday song');
        $fallbackUri = 'spotify:track:7AwQUXHJnDnEeeow7dfLGi';
        $birthdayUri = $fallbackUri;
        $searchTitle = $name . ', Dit is je verjaardag';
        $io->writeln('Query: ' . $searchTitle);
        try {
            $found = $this->spotify->searchTrackByTitle($searchTitle);
            if ($found) {
                $birthdayUri = $found;
                $io->writeln('<info>Found personalised song: ' . $birthdayUri . '</info>');
            } else {
                $io->writeln('<comment>No exact match — using fallback: ' . $fallbackUri . '</comment>');
            }
        } catch (\Throwable $e) {
            $io->warning('Spotify search failed: ' . $e->getMessage());
        }

        // ── Show popup ─────────────────────────────────────────────────────
        $io->section('Showing birthday popup');
        $this->radioState->setBirthday($name, $picture);
        $io->writeln('<info>Popup is now visible on the SRS FM screen.</info>');

        if ($noAudio) {
            $io->writeln('Press Ctrl+C to close the popup.');
            while ($this->running) {
                if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
                sleep(1);
            }
            $this->radioState->clearBirthday();
            $io->success('Birthday popup cleared.');
            return Command::SUCCESS;
        }

        // ── Play birthday song ─────────────────────────────────────────────
        $io->section('Playing birthday song');
        $trackId = str_replace('spotify:track:', '', $birthdayUri);
        try {
            if ($hasSonosApi) {
                $io->writeln(sprintf('<comment>Via Sonos API: %s</comment>', $birthdayUri));
                $this->sonosApi->playSpotifyTrack($birthdayUri, 'Happy Birthday ' . $name, 'SRS FM');

                sleep(5);
                while ($this->running) {
                    if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
                    try {
                        $playback  = $this->sonosApi->getPlayback();
                        if (empty($playback) || !$playback['is_playing']) break;
                        $remaining = ($playback['duration_ms'] - $playback['progress_ms']) / 1000;
                        $io->writeln(sprintf('<comment>%.0fs remaining...</comment>', $remaining));
                        if ($remaining <= 3) break;
                        sleep(min(10, max(1, (int) $remaining - 3)));
                    } catch (\Throwable) {
                        break;
                    }
                }
            } else {
                // UPnP: play Spotify track directly on Sonos
                $io->writeln(sprintf('<comment>Via Sonos UPnP: %s</comment>', $birthdayUri));
                $this->sonos->play($trackId, 'Happy Birthday ' . $name, 'SRS FM');

                sleep(5);
                while ($this->running) {
                    if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
                    try {
                        if (!$this->sonos->isPlaying()) break;
                        $pos = $this->sonos->getPositionInfo();
                        if ($pos['duration'] === 0) { sleep(2); continue; }
                        $remaining = max(0, $pos['duration'] - $pos['position']);
                        $io->writeln(sprintf('<comment>%.0fs remaining...</comment>', $remaining));
                        if ($remaining <= 3) break;
                        sleep(min(10, max(1, (int) $remaining - 3)));
                    } catch (\Throwable) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $io->warning('Song playback failed: ' . $e->getMessage());
        }

        // ── Cleanup ────────────────────────────────────────────────────────
        $this->radioState->clearBirthday();
        $io->success('Birthday test complete — popup cleared.');

        return Command::SUCCESS;
    }
}
