<?php

namespace App\Command;

use App\Repository\ThemeVoteRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'theme-vote:close', description: 'Close Theme Thursday voting and announce winner')]
class ThemeVoteCloseCommand extends Command
{
    public function __construct(
        private ThemeVoteRepository $themeVoteRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force close even if not Wednesday');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Amsterdam'));
        $dayOfWeek = (int) $now->format('N');

        if (!$force && $dayOfWeek !== 3) {
            $io->error('Voting should be closed on Wednesday. Use --force to override.');
            return Command::FAILURE;
        }

        $monday = $now->modify('monday this week')->format('Y-m-d');

        $counts = $this->themeVoteRepository->getVoteCounts($monday);
        if (empty($counts)) {
            $io->warning('No votes cast this week. No theme will be active.');
            return Command::SUCCESS;
        }

        $winner = $counts[0]['theme'];
        $votes = $counts[0]['votes'];

        $io->success(sprintf('Theme Thursday winner for %s: <info>%s</info> (%d votes)', $monday, $winner, $votes));
        $io->table(['Theme', 'Votes'], $counts);

        return Command::SUCCESS;
    }
}