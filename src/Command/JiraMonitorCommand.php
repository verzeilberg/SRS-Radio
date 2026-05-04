<?php
namespace App\Command;

use App\Service\JiraService;
use App\Service\SonosApiService;
use App\Service\SonosService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'jira:monitor', description: 'Monitor Jira for highest-priority tickets and trigger an alarm')]
class JiraMonitorCommand extends Command
{
    public function __construct(
        private JiraService $jira,
        private SonosApiService $sonosApi,
        private SonosService $sonos,
        private string $alarmClipUrl,
        private string $alarmLabels,
        private string $alarmAccount,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('interval', null, InputOption::VALUE_OPTIONAL, 'Poll interval in seconds', 60)
            ->addOption('once', null, InputOption::VALUE_NONE, 'Check once and exit (useful for cron)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $interval = (int) $input->getOption('interval');
        $once     = $input->getOption('once');
        $labels   = array_values(array_filter(array_map('trim', explode(',', $this->alarmLabels))));

        $io->title('Jira High-Priority Monitor');
        $io->writeln(sprintf('Account : <info>%s</info>', $this->alarmAccount));
        $io->writeln(sprintf('Labels  : <info>%s</info>', $labels ? implode(', ', $labels) : '(none)'));
        $io->writeln(sprintf('Interval: <info>%ds</info>', $interval));
        $io->newLine();

        $roomName = $this->sonos->getRoomName();
        $this->sonosApi->discoverGroup($roomName);

        $knownKeys = [];
        $firstRun  = true;

        while (true) {
            try {
                $tickets     = $this->jira->getHighestPriorityTickets($labels, $this->alarmAccount);
                $currentKeys = array_keys($tickets);

                if ($firstRun) {
                    $knownKeys = $currentKeys;
                    $firstRun  = false;
                    $io->writeln(sprintf('[%s] Baseline: %d open highest-priority ticket(s).', $this->now(), count($knownKeys)));
                } else {
                    $newKeys = array_diff($currentKeys, $knownKeys);

                    foreach ($newKeys as $key) {
                        $ticket = $tickets[$key];
                        $io->warning(sprintf(
                            '[ALARM] %s — %s (status: %s)',
                            $ticket['key'],
                            $ticket['summary'],
                            $ticket['status'],
                        ));
                        $this->triggerAlarm($io);
                    }

                    $resolvedKeys = array_diff($knownKeys, $currentKeys);
                    foreach ($resolvedKeys as $key) {
                        $io->writeln(sprintf('[%s] <info>Resolved:</info> %s', $this->now(), $key));
                    }

                    $knownKeys = $currentKeys;
                }
            } catch (\Throwable $e) {
                $io->error('Jira check failed: ' . $e->getMessage());
            }

            if ($once) {
                break;
            }

            sleep($interval);
        }

        return Command::SUCCESS;
    }

    private function triggerAlarm(SymfonyStyle $io): void
    {
        if (!$this->alarmClipUrl) {
            $io->warning('No alarm clip URL configured (JIRA_ALARM_CLIP_URL).');
            return;
        }

        if ($this->sonosApi->playAudioClip($this->alarmClipUrl)) {
            $io->writeln('<info>Alarm gespeeld via Sonos Cloud API.</info>');
            return;
        }

        // Fallback: play directly via UPnP
        try {
            $this->sonos->playHttpClip($this->alarmClipUrl);
            $io->writeln('<info>Alarm gespeeld via UPnP.</info>');
        } catch (\Throwable $e) {
            $io->warning('Alarm afspelen mislukt: ' . $e->getMessage());
        }
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Amsterdam')))->format('H:i:s');
    }
}
