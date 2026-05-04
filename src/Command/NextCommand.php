<?php
namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'radio:next', description: 'Skip the current song or DJ clip')]
class NextCommand extends Command
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

        if ($pid <= 0 || !posix_kill($pid, 0)) {
            $io->warning("Process $pid is not running.");
            return Command::SUCCESS;
        }

        posix_kill($pid, SIGUSR1);
        $io->success('Skipping to next...');

        return Command::SUCCESS;
    }
}
