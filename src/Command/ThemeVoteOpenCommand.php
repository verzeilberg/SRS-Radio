<?php

namespace App\Command;

use App\Repository\ThemeVoteRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'theme-vote:open', description: 'Open Theme Thursday voting for this week')]
class ThemeVoteOpenCommand extends Command
{
    public function __construct(
        private ThemeVoteRepository $themeVoteRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force open even if not Mon-Wed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Amsterdam'));
        $dayOfWeek = (int) $now->format('N');

        if (!$force && ($dayOfWeek < 1 || $dayOfWeek > 3)) {
            $io->error('Voting can only be opened Monday-Wednesday. Use --force to override.');
            return Command::FAILURE;
        }

        $monday = $now->modify('monday this week')->format('Y-m-d');

        $existing = $this->themeVoteRepository->getVoteCounts($monday);
        if (!empty($existing)) {
            $io->warning(sprintf('Voting already open for week %s', $monday));
            $this->showStatus($io, $monday);
            return Command::SUCCESS;
        }

        $io->success(sprintf('Theme Thursday voting opened for week %s', $monday));
        $io->writeln('Available themes: 80s, 90s, 00s, Dance, Rock, Dutch, Soul, Disco, House, Techno');

        return Command::SUCCESS;
    }

    private function showStatus(SymfonyStyle $io, string $week): void
    {
        $counts = $this->themeVoteRepository->getVoteCounts($week);
        if (empty($counts)) {
            $io->writeln('No votes yet.');
            return;
        }
        $io->table(['Theme', 'Votes'], $counts);
    }
}