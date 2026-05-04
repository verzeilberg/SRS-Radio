<?php
namespace App\Command;

use App\Service\SonosService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'radio:sonos-info', description: 'Show Sonos device info and configured music service accounts')]
class SonosInfoCommand extends Command
{
    public function __construct(private SonosService $sonos)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->section('Device');
        $io->writeln('Room: <info>' . $this->sonos->getRoomName() . '</info>');

        $io->section('Spotify service (auto-discovered)');
        $ms = $this->sonos->getMusicServicesRaw();
        if (preg_match('/&lt;Service Id=&quot;(\d+)&quot; Name=&quot;Spotify&quot;/', $ms['body'], $m)) {
            $io->writeln('sid (service ID) : <info>' . $m[1] . '</info>');
        } else {
            $io->writeln('sid : <comment>not found in service list</comment>');
        }

        $accounts = $this->sonos->getAccounts();
        $spotifyAccount = array_filter($accounts, fn($a) => $a['type'] === '2311');
        if ($spotifyAccount) {
            $a = reset($spotifyAccount);
            $io->writeln('sn (serial num)  : <info>' . $a['serial_num'] . '</info> (from /status/accounts)');
        } else {
            // S2 firmware: /status/accounts is empty; try reading sn from current position info URI
            $pos = $this->sonos->getPositionInfoRaw();
            if (preg_match('/sn=(\d+)/', $pos, $m)) {
                $io->writeln('sn (serial num)  : <info>' . $m[1] . '</info> (from current track URI — set SONOS_SPOTIFY_SN=' . $m[1] . ' in .env.local)');
            } else {
                $io->writeln('sn (serial num)  : <comment>not found — is Spotify playing on PHPSD right now?</comment>');
                $io->writeln('Tip: start playing any Spotify track on PHPSD, then re-run this command.');
            }
        }

        return Command::SUCCESS;
    }
}
