<?php

namespace App\Command;

use App\Repository\ThemeVoteRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'theme-vote:status', description: 'Show current Theme Thursday voting status')]
class ThemeVoteStatusCommand extends Command
{
    public function __construct(
        private ThemeVoteRepository $themeVoteRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Amsterdam'));
        $monday = $now->modify('monday this week')->format('Y-m-d');
        $dayOfWeek = (int) $now->format('N');
        $dayNames = ['Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za', 'Zo'];

        $io->title(sprintf('Theme Thursday Status — Week %s (%s)', $monday, $dayNames[$dayOfWeek - 1]));

        $isOpen = $dayOfWeek >= 1 && $dayOfWeek <= 3;
        $isThursday = $dayOfWeek === 4;

        if ($isOpen) {
            $io->writeln('<info>Stemming: OPEN</info> (Ma-Wo)');
        } elseif ($isThursday) {
            $io->writeln('<comment>Vandaag is Theme Thursday!</comment>');
        } else {
            $io->writeln('<comment>Stemming: GESLOTEN</comment> (opent maandag)');
        }

        $counts = $this->themeVoteRepository->getVoteCounts($monday);
        if (empty($counts)) {
            $io->writeln('Geen stemmen voor deze week.');
        } else {
            $io->table(['Thema', 'Stemmen'], $counts);
            $winner = $counts[0]['theme'];
            if ($isThursday) {
                $io->success(sprintf('Actief thema vandaag: <info>%s</info>', $winner));
            } elseif (!$isOpen) {
                $io->writeln(sprintf('Winnaar voor deze donderdag: <info>%s</info>', $winner));
            }
        }

        return Command::SUCCESS;
    }
}