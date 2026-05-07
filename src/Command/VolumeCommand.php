<?php
namespace App\Command;

use App\Service\SonosApiService;
use App\Service\SonosService;
use App\Service\SpotifyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'radio:volume', description: 'Get or set the playback volume')]
class VolumeCommand extends Command
{
    public function __construct(
        private SonosService    $sonos,
        private SonosApiService $sonosApi,
        private SpotifyService  $spotify,
        private string          $djClipIp = '',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'value',
                InputArgument::OPTIONAL,
                'up / down / 0–100. Omit to show current volume.',
            )
            ->addOption('step', 's', InputOption::VALUE_REQUIRED, 'Step size for up/down', 10);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $step = max(1, min(100, (int) $input->getOption('step')));

        [$backend, $current] = $this->detectBackend($io);

        if ($current === null) {
            $io->error('Could not reach any playback device (Sonos, Spotify Connect, or soundbar).');
            return Command::FAILURE;
        }

        $value = $input->getArgument('value');

        if ($value === null) {
            $io->writeln(sprintf('Volume: <info>%d</info>  [%s]', $current, $backend));
            return Command::SUCCESS;
        }

        $target = match (strtolower((string) $value)) {
            'up'    => min(100, $current + $step),
            'down'  => max(0,   $current - $step),
            default => max(0, min(100, (int) $value)),
        };

        try {
            $this->setVolume($backend, $target);
        } catch (\RuntimeException $e) {
            if ($backend !== 'spotify') {
                throw $e;
            }
            // Spotify rejected volume control (e.g. phone/restricted device) — fall back to hardware.
            $io->writeln(sprintf('<comment>Spotify volume refused (%s), trying hardware fallback...</comment>', $e->getMessage()));
            [$backend, $current] = $this->detectHardwareBackend($io);
            if ($current === null) {
                $io->error('No hardware volume control available either.');
                return Command::FAILURE;
            }
            $target = match (strtolower((string) $value)) {
                'up'    => min(100, $current + $step),
                'down'  => max(0,   $current - $step),
                default => max(0, min(100, (int) $value)),
            };
            $this->setVolume($backend, $target);
        }

        $bar   = str_repeat('█', (int) round($target / 5)) . str_repeat('░', 20 - (int) round($target / 5));
        $arrow = $target > $current ? '▲' : ($target < $current ? '▼' : '=');
        $io->writeln(sprintf('%s  %d → <info>%d</info>  [%s]  via %s', $arrow, $current, $target, $bar, $backend));

        return Command::SUCCESS;
    }

    /**
     * Try each backend in order and return [name, current-volume] for the first one that responds.
     * Order: Sonos API → Spotify Connect → DLNA soundbar → Sonos UPnP.
     */
    private function detectBackend(SymfonyStyle $io): array
    {
        // 1. Sonos Cloud API (PHPSD at work)
        try {
            $roomName = $this->sonos->getRoomName();
            if ($roomName && $this->sonosApi->discoverGroup($roomName)) {
                $vol = $this->sonosApi->getGroupVolume();
                if ($vol !== null) {
                    $io->writeln(sprintf('<comment>Backend: Sonos API (%s)</comment>', $roomName));
                    return ['sonos_api', $vol];
                }
            }
        } catch (\Throwable) {}

        // 2. Spotify Connect (soundbar or computer via Spotify)
        try {
            $playback = $this->spotify->getCurrentPlayback();
            if (!empty($playback) && isset($playback['volume_percent'])) {
                $io->writeln(sprintf('<comment>Backend: Spotify Connect (%s)</comment>', $playback['device_name'] ?? '?'));
                return ['spotify', $playback['volume_percent']];
            }
        } catch (\Throwable) {}

        // 3. DLNA soundbar via UPnP RenderingControl (home soundbar)
        if ($this->djClipIp !== '') {
            try {
                if ($this->sonos->discoverDlnaDevice($this->djClipIp)) {
                    $vol = $this->sonos->getVolume();
                    $io->writeln(sprintf('<comment>Backend: DLNA (%s)</comment>', $this->djClipIp));
                    return ['dlna', $vol];
                }
            } catch (\Throwable) {}
        }

        // 4. Sonos UPnP direct (fallback)
        try {
            $vol = $this->sonos->getVolume();
            $io->writeln('<comment>Backend: Sonos UPnP</comment>');
            return ['sonos_upnp', $vol];
        } catch (\Throwable) {}

        return ['none', null];
    }

    private function detectHardwareBackend(SymfonyStyle $io): array
    {
        if ($this->djClipIp !== '') {
            try {
                if ($this->sonos->discoverDlnaDevice($this->djClipIp)) {
                    $vol = $this->sonos->getVolume();
                    $io->writeln(sprintf('<comment>Backend: DLNA (%s)</comment>', $this->djClipIp));
                    return ['dlna', $vol];
                }
            } catch (\Throwable) {}
        }

        try {
            $vol = $this->sonos->getVolume();
            $io->writeln('<comment>Backend: Sonos UPnP</comment>');
            return ['sonos_upnp', $vol];
        } catch (\Throwable) {}

        return ['none', null];
    }

    private function setVolume(string $backend, int $target): void
    {
        match ($backend) {
            'sonos_api'  => $this->sonosApi->setGroupVolume($target),
            'spotify'    => $this->spotify->setVolume($target),
            default      => $this->sonos->setVolume($target),
        };
    }
}
