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

        if (!file_exists($pidFile)) {
            $io->warning('Radio is not running (no PID file found).');
            return Command::SUCCESS;
        }

        $pid = (int) trim(file_get_contents($pidFile));

        if ($pid <= 0) {
            $io->error('PID file is invalid.');
            return Command::FAILURE;
        }

        if (!posix_kill($pid, 0)) {
            $io->warning("Process $pid is no longer running. Cleaning up stale PID file.");
            @unlink($pidFile);
            return Command::SUCCESS;
        }

        $io->writeln("Sending stop signal to radio process (PID $pid)...");
        posix_kill($pid, SIGTERM);

        // Wait up to 30 seconds for the process to exit
        for ($i = 0; $i < 30; $i++) {
            sleep(1);
            if (!posix_kill($pid, 0)) {
                $io->success('Radio stopped.');
                return Command::SUCCESS;
            }
        }

        $io->error("Process $pid did not stop within 30 seconds.");
        return Command::FAILURE;
    }
}
