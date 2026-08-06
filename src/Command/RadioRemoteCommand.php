<?php

namespace App\Command;

use App\Service\RemoteRadioService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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
            'start'  => $this->start($io),
            'stop'   => $this->runAction($io, $this->remote->stop()),
            'next'   => $this->runAction($io, $this->remote->next()),
            default  => $this->unknown($io, $action),
        };
    }

    private function start(SymfonyStyle $io): int
    {
        if ($this->isLocalRadioRunning()) {
            $io->error('Radio is already running on this machine. Run "bin/console radio:stop" first.');
            return Command::FAILURE;
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
        $pidFile = RadioStartCommand::pidFile();
        if (file_exists($pidFile)) {
            $pid = (int) trim(file_get_contents($pidFile));
            if ($pid > 0 && posix_kill($pid, 0)) {
                return true;
            }
        }

        if (!function_exists('exec')) {
            return false;
        }

        $output = [];
        $code   = 0;
        exec('pgrep -f "[b]in/console radio:start" 2>/dev/null', $output, $code);
        foreach ($output as $line) {
            $pid = (int) trim($line);
            if ($pid > 0 && $pid !== getmypid() && posix_kill($pid, 0)) {
                return true;
            }
        }

        return false;
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
