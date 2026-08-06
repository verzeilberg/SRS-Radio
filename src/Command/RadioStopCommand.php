<?php
namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'radio:stop', description: 'Stop the running radio station gracefully')]
class RadioStopCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $pidFile = RadioStartCommand::pidFile();

        $pids = [];

        // Primary: read the PID file
        if (file_exists($pidFile)) {
            $pid = (int) trim(file_get_contents($pidFile));
            if ($pid > 0 && posix_kill($pid, 0)) {
                $pids[] = $pid;
            } else {
                $io->writeln("Cleaning up stale PID file (process $pid is gone).");
                @unlink($pidFile);
            }
        }

        // Fallback: also look for any radio:start processes via pgrep.
        // This catches instances running without a PID file.
        $foundPids = RadioStartCommand::findRunningPids();
        foreach ($foundPids as $foundPid) {
            if (!in_array($foundPid, $pids, true)) {
                $pids[] = $foundPid;
            }
        }

        if (empty($pids)) {
            $io->warning('Radio is not running (no PID file or process found).');
            return Command::SUCCESS;
        }

        foreach ($pids as $pid) {
            $io->writeln("Sending stop signal to radio process (PID $pid)...");
            posix_kill($pid, SIGTERM);
        }

        // Wait up to 30 seconds for all processes to exit
        $allStopped = false;
        for ($i = 0; $i < 30; $i++) {
            sleep(1);
            $alive = array_filter($pids, fn(int $p) => posix_kill($p, 0));
            if (empty($alive)) {
                $allStopped = true;
                break;
            }
        }

        // Only fall back to the stop flag file if a process ignored SIGTERM.
        // Writing it unconditionally leaves a stale flag that would immediately
        // stop the next radio:start.
        if (!$allStopped) {
            file_put_contents(RadioStartCommand::stopFlagFile(), '1');
        }

        @unlink($pidFile);

        if ($allStopped) {
            $io->success('Radio stopped.');
            return Command::SUCCESS;
        }

        $io->error('Radio process(es) did not stop within 30 seconds: ' . implode(', ', $alive ?? $pids));
        return Command::FAILURE;
    }
}
