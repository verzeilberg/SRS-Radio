<?php
namespace App\Command;

use App\Service\SonosApiService;
use App\Service\SonosService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'radio:volume', description: 'Get or set the Sonos volume')]
class VolumeCommand extends Command
{
    public function __construct(
        private SonosService    $sonos,
        private SonosApiService $sonosApi,
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

        // Prefer Sonos Cloud API when a group has been discovered; fall back to UPnP.
        $useApi = $this->discoverApi($io);

        $current = $useApi ? $this->sonosApi->getGroupVolume() : $this->sonos->getVolume();
        if ($current === null) {
            $io->error('Kon het huidige volume niet ophalen.');
            return Command::FAILURE;
        }

        $value = $input->getArgument('value');

        if ($value === null) {
            $io->writeln(sprintf('Huidig volume: <info>%d</info>', $current));
            return Command::SUCCESS;
        }

        $target = match (strtolower((string) $value)) {
            'up'    => min(100, $current + $step),
            'down'  => max(0,   $current - $step),
            default => (int) $value,
        };

        $target = max(0, min(100, $target));

        if ($useApi) {
            $this->sonosApi->setGroupVolume($target);
        } else {
            $this->sonos->setVolume($target);
        }

        $bar   = str_repeat('█', (int) round($target / 5)) . str_repeat('░', 20 - (int) round($target / 5));
        $arrow = $target > $current ? '▲' : ($target < $current ? '▼' : '=');
        $io->writeln(sprintf('%s  %d → <info>%d</info>  [%s]', $arrow, $current, $target, $bar));

        return Command::SUCCESS;
    }

    private function discoverApi(SymfonyStyle $io): bool
    {
        try {
            $roomName = $this->sonos->getRoomName();
            if ($roomName && $this->sonosApi->discoverGroup($roomName)) {
                return true;
            }
        } catch (\Throwable) {}

        return false;
    }
}
