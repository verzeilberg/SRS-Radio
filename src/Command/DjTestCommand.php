<?php
namespace App\Command;

use App\DTO\DjContext;
use App\Entity\DjAnnouncement;
use App\Service\DjScriptService;
use App\Service\RadioStateService;
use App\Service\SonosService;
use App\Service\SpotifyService;
use App\Service\TextToSpeechService;
use App\Service\WeatherService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'radio:dj-test', description: 'Test a DJ announcement immediately')]
class DjTestCommand extends Command
{
    private const VALID_TYPES = ['between_tracks', 'morning', 'lunch', 'afternoon', 'friday_afternoon', 'end_of_day', 'weather'];

    public function __construct(
        private DjScriptService $djService,
        private TextToSpeechService $tts,
        private SonosService $sonos,
        private SpotifyService $spotify,
        private RadioStateService $radioState,
        private WeatherService $weather,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::OPTIONAL, 'Type: ' . implode(', ', self::VALID_TYPES), 'between_tracks')
            ->addOption('voice',  null, InputOption::VALUE_OPTIONAL, 'edge-tts voice name (overrides DJ_VOICE env var)')
            ->addOption('device', null, InputOption::VALUE_OPTIONAL, 'Play in browser instead of Sonos (see radio:devices)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $type = $input->getArgument('type');

        if (!in_array($type, self::VALID_TYPES, true)) {
            $io->error('Ongeldig type. Kies uit: ' . implode(', ', self::VALID_TYPES));
            return Command::FAILURE;
        }

        $io->title('DJ Test — ' . $type);

        $weatherData = $type === 'weather' ? $this->weather->getCurrent() : null;

        $text = $this->djService->generate(new DjContext(
            station: 'SRS FM',
            track: 'Wake Me Up',
            artist: 'Avicii',
            mood: 'energetic',
            hour: (int) date('H'),
            type: $type,
            weather: $weatherData,
        ));

        $io->writeln('<info>Tekst:</info> ' . $text);
        $io->writeln('TTS genereren...');

        if ($voice = $input->getOption('voice')) {
            $this->tts->setVoice($voice);
        }

        $audioUrl = $this->tts->generate($text);
        $io->writeln('<info>Audio:</info> ' . $audioUrl);

        if ($input->getOption('device') !== null) {
            $duration = $this->tts->getDuration($audioUrl);
            $io->writeln(sprintf('Afspelen in browser (%.1fs)...', $duration));

            $playback = $this->spotify->getCurrentPlayback();
            $volume   = $playback['volume_percent'] ?? 50;

            $this->fadeVolume($volume, 0);

            $this->radioState->setTrack('DJ Sander', 'SRS FM', (int) ($duration * 1000));
            $this->radioState->setDjClip($audioUrl);

            $end = microtime(true) + $duration;
            while (microtime(true) < $end) {
                usleep(200000);
            }

            $this->radioState->clearDjClip();

            $this->fadeVolume(0, $volume);
        } else {
            $io->writeln('Afspelen op Sonos...');
            $this->sonos->playHttpClip($audioUrl);
            $this->sonos->waitForClipToEnd();
        }

        $this->tts->delete($audioUrl);

        $this->em->persist(new DjAnnouncement($text, $audioUrl, $type));
        $this->em->flush();

        $io->success('Klaar.');
        return Command::SUCCESS;
    }

    private function fadeVolume(int $from, int $to, int $steps = 20, int $stepMs = 75): void
    {
        for ($i = 1; $i <= $steps; $i++) {
            $vol = (int) round($from + ($to - $from) * $i / $steps);
            $this->spotify->setVolume(max(0, min(100, $vol)));
            usleep($stepMs * 1000);
        }
    }
}
