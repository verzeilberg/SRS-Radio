<?php
namespace App\Command;

use App\Service\JiraService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'jira:monitor', description: 'Monitor Jira for highest-priority tickets and trigger the radio alarm')]
class JiraMonitorCommand extends Command
{
    public function __construct(
        private JiraService $jira,
        private string $projectDir,
        private string $alarmClipUrl,
        private string $alarmLabels,
        private string $alarmAccount,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('interval', null, InputOption::VALUE_OPTIONAL, 'Poll interval in seconds', 5)
            ->addOption('once',     null, InputOption::VALUE_NONE,     'Check once and exit')
            ->addOption('test',     null, InputOption::VALUE_NONE,     'Write a test alarm immediately and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $interval = (int) $input->getOption('interval');
        $labels   = array_values(array_filter(array_map('trim', explode(',', $this->alarmLabels))));

        $io->title('Jira High-Priority Monitor');
        $io->writeln(sprintf('Account : <info>%s</info>', $this->alarmAccount));
        $io->writeln(sprintf('Labels  : <info>%s</info>', $labels ? implode(', ', $labels) : '(none)'));
        $io->writeln(sprintf('Interval: <info>%ds</info>', $interval));
        $io->newLine();

        if ($input->getOption('test')) {
            $io->warning('TEST MODE — writing alarm file now.');
            $this->writeAlarmFile([
                'key'     => 'TEST-1',
                'summary' => 'Test alarm — this is a test of the emergency broadcast system',
                'status'  => 'open',
            ]);
            $io->writeln('Alarm file written. radio:start will play the siren on the next loop tick.');
            return Command::SUCCESS;
        }

        $knownKeys = [];
        $firstRun  = true;

        while (true) {
            try {
                $tickets     = $this->jira->getHighestPriorityTickets($labels, $this->alarmAccount);
                $currentKeys = array_keys($tickets);

                $this->writeStateFile($tickets);

                if ($firstRun) {
                    $knownKeys = $currentKeys;
                    $firstRun  = false;
                    $io->writeln(sprintf('[%s] Baseline: %d open highest-priority ticket(s).', $this->now(), count($knownKeys)));
                } else {
                    foreach (array_diff($currentKeys, $knownKeys) as $key) {
                        $ticket = $tickets[$key];
                        $io->warning(sprintf('[ALARM] New highest ticket: %s — %s', $ticket['key'], $ticket['summary']));
                        $this->writeAlarmFile($ticket);
                    }

                    foreach (array_diff($knownKeys, $currentKeys) as $key) {
                        $io->writeln(sprintf('[%s] <info>Resolved:</info> %s', $this->now(), $key));
                    }

                    $knownKeys = $currentKeys;
                }
            } catch (\Throwable $e) {
                $io->error('Jira check failed: ' . $e->getMessage());
            }

            if ($input->getOption('once')) {
                break;
            }

            sleep($interval);
        }

        return Command::SUCCESS;
    }

    private function writeAlarmFile(array $ticket): void
    {
        file_put_contents(
            $this->projectDir . '/var/jira-alarm.json',
            json_encode([
                'url'     => $this->alarmClipUrl,
                'key'     => $ticket['key'],
                'summary' => $ticket['summary'],
            ])
        );
    }

    private function writeStateFile(array $tickets): void
    {
        file_put_contents(
            $this->projectDir . '/var/jira-state.json',
            json_encode(['updated_at' => time(), 'tickets' => array_values($tickets)])
        );
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Amsterdam')))->format('H:i:s');
    }
}
