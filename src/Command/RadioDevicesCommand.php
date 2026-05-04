<?php
namespace App\Command;

use App\Service\SpotifyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'radio:devices', description: 'List available Spotify devices')]
class RadioDevicesCommand extends Command
{
    public function __construct(private SpotifyService $spotify)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $devices = $this->spotify->getDevices();

        if (empty($devices)) {
            $io->warning('No active Spotify devices found. Open Spotify on a device first.');
            return Command::FAILURE;
        }

        $io->table(['Name', 'Type', 'Active'], array_map(fn($d) => [
            $d['name'],
            $d['type'],
            $d['is_active'] ? 'yes' : 'no',
        ], $devices));

        return Command::SUCCESS;
    }
}
