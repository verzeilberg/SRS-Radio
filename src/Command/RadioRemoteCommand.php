<?php

namespace App\Command;

use App\Service\RemoteRadioService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'radio:remote', description: 'Control the radio on a remote host over SSH')]
class RadioRemoteCommand extends Command
{
    public function __construct(private readonly RemoteRadioService $remote)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::OPTIONAL, 'Action: status, start, stop, next', 'status');
        $this->addOption(
            'stop-local',
            null,
            InputOption::VALUE_NONE,
            'With "start": gracefully stop the radio on this machine before starting it remotely',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');

        if (!$this->remote->isConfigured()) {
            $io->error('No remote host configured. Set REMOTE_RADIO_HOST / REMOTE_RADIO_USER in .env.local');
            return Command::FAILURE;
        }

        return match ($action) {
            'status' => $this->status($io),
            'start'  => $this->start($io, (bool) $input->getOption('stop-local')),
            'stop'   => $this->runAction($io, $this->remote->stop()),
            'next'   => $this->runAction($io, $this->remote->next()),
            default  => $this->unknown($io, $action),
        };
    }

    private function start(SymfonyStyle $io, bool $stopLocal): int
    {
        if ($this->isLocalRadioRunning()) {
            if (!$stopLocal) {
                $io->error('Radio is already running on this machine. Run "bin/console radio:stop" first, or use --stop-local.');
                return Command::FAILURE;
            }
            $this->stopLocalRadio($io);
        }

        return $this->runAction($io, $this->remote->start());
    }

    /**
     * Whether a radio:start process is already running on this machine.
     * Mirrors the local checks in RadioStartCommand so a remote start never
     * conflicts with a radio running on the controlling machine.
     */
    private function isLocalRadioRunning(): bool
    {
        return $this->findLocalRadioPids() !== [];
    }

    /**
     * Gracefully stop the local radio: signal the process and write the stop
     * flag so the daemon picks it up even if SIGTERM is missed.
     */
    private function stopLocalRadio(SymfonyStyle $io): void
    {
        $pids = $this->findLocalRadioPids();
        foreach ($pids as $pid) {
            $io->writeln(sprintf('Stopping local radio (PID %d)…', $pid));
            posix_kill($pid, SIGTERM);
        }
        file_put_contents(RadioStartCommand::stopFlagFile(), '1');

        for ($i = 0; $i < 15 && $this->isLocalRadioRunning(); $i++) {
            sleep(1);
        }

        $io->writeln(
            $this->isLocalRadioRunning()
                ? '<comment>Local radio did not stop within 15s — starting remote anyway.</comment>'
                : '<info>Local radio stopped.</info>'
        );
    }

    /**
     * @return list<int>
     */
    private function findLocalRadioPids(): array
    {
        $pids = [];

        $pidFile = RadioStartCommand::pidFile();
        if (file_exists($pidFile)) {
            $pid = (int) trim(file_get_contents($pidFile));
            if ($pid > 0 && posix_kill($pid, 0)) {
                $pids[] = $pid;
            }
        }

        foreach (RadioStartCommand::findRunningPids() as $pid) {
            if (!in_array($pid, $pids, true)) {
                $pids[] = $pid;
            }
        }

        return $pids;
    }

    private function status(SymfonyStyle $io): int
    {
        $status = $this->remote->status();

        if (!($status['reachable'] ?? false)) {
            $io->warning('Remote host ' . $this->remote->getName() . ' is not reachable.');
            $io->text('Install the SSH key first: ssh-copy-id ' . $this->remote->getName());
            return Command::FAILURE;
        }

        $state = $status['state'] ?? [];

        $io->title('Remote radio: ' . $this->remote->getName());
        $io->text([
            'Reachable:   ' . ($status['reachable'] ? 'yes' : 'no'),
            'Running:     ' . ($status['running'] ? 'yes (PID ' . $status['pid'] . ')' : 'no'),
            'Status:      ' . ($state['status'] ?? '—'),
            'Now playing: ' . ($state['track_title'] ?? '—') . ' — ' . ($state['track_artist'] ?? ''),
        ]);

        return Command::SUCCESS;
    }

    private function runAction(SymfonyStyle $io, array $result): int
    {
        if ($result['success']) {
            $io->success($result['message']);
            return Command::SUCCESS;
        }

        $io->error($result['message']);
        return Command::FAILURE;
    }

    private function unknown(SymfonyStyle $io, string $action): int
    {
        $io->error('Unknown action "' . $action . '" (use status, start, stop or next)');
        return Command::FAILURE;
    }
}
