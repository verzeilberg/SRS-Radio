<?php
namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'radio:dj-voices', description: 'List available edge-tts voices')]
class DjVoicesCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('lang', null, InputOption::VALUE_OPTIONAL, 'Filter by language code (e.g. nl, en, de)', 'nl');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $lang = $input->getOption('lang');

        $raw = shell_exec('edge-tts --list-voices 2>&1');
        if (!$raw) {
            $io->error('edge-tts niet gevonden of geen output.');
            return Command::FAILURE;
        }

        $rows = [];
        foreach (explode("\n", trim($raw)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'Name')) {
                continue;
            }
            // Format: Name  Gender  Locale  ...
            $parts = preg_split('/\s+/', $line, 5);
            if (count($parts) < 3) {
                continue;
            }
            [$name, $gender, $locale] = $parts;
            if ($lang && !str_contains(strtolower($name), strtolower($lang))) {
                continue;
            }
            $rows[] = [$name, $gender, $locale];
        }

        if (empty($rows)) {
            $io->warning('Geen stemmen gevonden voor taalfilter: ' . $lang);
            return Command::SUCCESS;
        }

        $io->title('Beschikbare stemmen' . ($lang ? ' (' . $lang . ')' : ''));
        $io->table(['Stem', 'Geslacht', 'Locale'], $rows);
        $io->writeln('Gebruik: <info>bin/console radio:dj-test between_tracks --voice nl-NL-MaartenNeural</info>');
        $io->writeln('Of stel in via <info>DJ_VOICE</info> in .env.local');

        return Command::SUCCESS;
    }
}
