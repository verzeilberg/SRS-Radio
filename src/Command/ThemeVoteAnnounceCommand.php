<?php

namespace App\Command;

use App\Repository\ThemeVoteRepository;
use App\Service\TeamsNotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'theme-vote:announce', description: 'Announce Theme Thursday voting round on Teams')]
class ThemeVoteAnnounceCommand extends Command
{
    private const THEMES = [
        '80s',
        '90s',
        '00s',
        'Dance',
        'Rock',
        'Dutch',
        'Soul',
        'Disco',
        'House',
        'Techno',
    ];

    public function __construct(
        private ThemeVoteRepository $themeVoteRepository,
        private ?TeamsNotificationService $teamsNotificationService,
        private string $language = 'en',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force announce even if not Monday')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show message without sending to Teams');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');
        $dryRun = $input->getOption('dry-run');

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Amsterdam'));
        $dayOfWeek = (int) $now->format('N');

        if (!$force && $dayOfWeek !== 1) {
            $io->error('Announcement should run on Monday. Use --force to override.');
            return Command::FAILURE;
        }

        $monday = $now->format('Y-m-d');

        $existing = $this->themeVoteRepository->getVoteCounts($monday);
        if (!empty($existing)) {
            $io->warning(sprintf('Voting already open for week %s', $monday));
            return Command::SUCCESS;
        }

        $message = $this->buildAnnouncementMessage($monday);

        if ($dryRun) {
            $io->title('Dry Run - Message Preview');
            $io->writeln($message);
            return Command::SUCCESS;
        }

        if ($this->teamsNotificationService === null) {
            $io->error('Teams notification service not configured. Set TEAMS_WEBHOOK_URL in .env');
            return Command::FAILURE;
        }

        $success = $this->teamsNotificationService->sendMessage($message, 'Theme Thursday Voting Open!');

        if ($success) {
            $io->success(sprintf('Theme Thursday voting announcement sent to Teams for week %s', $monday));
        } else {
            $io->error('Failed to send announcement to Teams');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function buildAnnouncementMessage(string $monday): string
    {
        $themesList = implode(', ', self::THEMES);
        $dateObj = new \DateTimeImmutable($monday);
        $weekLabel = $dateObj->format('F j');
        $isDutch = $this->language === 'nl';

        if ($isDutch) {
            $dutchMonths = [
                'January' => 'januari', 'February' => 'februari', 'March' => 'maart',
                'April' => 'april', 'May' => 'mei', 'June' => 'juni',
                'July' => 'juli', 'August' => 'augustus', 'September' => 'september',
                'October' => 'oktober', 'November' => 'november', 'December' => 'december',
            ];
            $weekLabel = strtr($weekLabel, $dutchMonths);

            return <<<MD
**Nieuwe Theme Thursday stemronde is open!** 🎵

**Week van {$weekLabel}**

Stem op het thema van deze week:
{$themesList}

Stemmen is open **maandag t/m woensdag**. De winnaar wordt woensdag bekendgemaakt en donderdag gespeeld!

Stem met het `/theme-vote` commando of reageer op dit bericht met je keuze.
MD;
        }

        return <<<MD
**New Theme Thursday voting round is open!** 🎵

**Week of {$weekLabel}**

Cast your vote for this week's theme:
{$themesList}

Voting is open **Monday through Wednesday**. The winning theme will be announced on Wednesday and played on Thursday!

Vote using the `/theme-vote` command or reply to this message with your choice.
MD;
    }
}