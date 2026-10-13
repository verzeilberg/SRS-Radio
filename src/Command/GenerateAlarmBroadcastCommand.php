<?php
namespace App\Command;

use App\DTO\DjContext;
use App\Service\DjScriptService;
use App\Service\RemoteRadioService;
use App\Service\SonosApiService;
use App\Service\SonosService;
use App\Service\SpotifyService;
use App\Service\TextToSpeechService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-alarm-broadcast',
    description: 'Generate and play custom DJ alarm broadcast (runs in background)'
)]
class GenerateAlarmBroadcastCommand extends Command
{
    public function __construct(
        private DjScriptService $djService,
        private TextToSpeechService $tts,
        private SonosService $sonos,
        private SonosApiService $sonosApi,
        private SpotifyService $spotify,
        private RemoteRadioService $remote,
        private EntityManagerInterface $em,
        private HttpClientInterface $httpClient,
        private string $projectDir,
        private string $djClipIp = '',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('key', InputArgument::REQUIRED, 'Jira ticket key')
            ->addArgument('summary', InputArgument::REQUIRED, 'Jira ticket summary');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $key     = $input->getArgument('key');
        $summary = $input->getArgument('summary');

        // Connect to Sonos
        try {
            $roomName = $this->sonos->getRoomName();
            $groupId  = $this->sonosApi->discoverGroup($roomName);
            if (!$groupId) {
                $io->error('Could not connect to Sonos');
                return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            $io->error('Sonos connection failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        try {
            // Generate DJ emergency broadcast text
            $djText = $this->djService->generate(new DjContext(
                station: 'SRS FM',
                track:   '',
                artist:  '',
                mood:    'urgent',
                hour:    (int) date('H'),
                type:    'alarm',
                alarmKey:     $key,
                alarmSummary: $summary,
            ));

            $io->writeln('[ALARM] DJ: ' . $djText);

            $sirenPath = $this->projectDir . '/public/sounds/air_raid_siren.mp3';
            $audioUrl  = $this->tts->generateWithBed($djText, $sirenPath, 0.35);
            $duration  = $this->tts->getDuration($audioUrl);

            $io->writeln(sprintf('[ALARM] Custom broadcast: %s (%.1fs)', $audioUrl, $duration));

            // Play via Sonos API
            $groupVol = $this->sonosApi->getGroupVolume() ?? 40;
            $clipVol  = min(100, $groupVol + 5);
            $this->sonosApi->playAudioClip($audioUrl, $clipVol);

            // Wait for playback to finish
            $end = microtime(true) + $duration + 2.0;
            while (microtime(true) < $end) {
                usleep(300_000);
            }

            $this->tts->delete($audioUrl);
            $io->writeln('[ALARM] Custom broadcast done.');

        } catch (\Throwable $e) {
            $io->error('[ALARM] Custom broadcast failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}