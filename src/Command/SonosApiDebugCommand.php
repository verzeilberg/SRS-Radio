<?php
namespace App\Command;

use App\Entity\SonosToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'radio:sonos-api-debug', description: 'Test Sonos Cloud API connection')]
class SonosApiDebugCommand extends Command
{
    private const API = 'https://api.ws.sonos.com/control/api/v1';

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $token = $this->em->getRepository(SonosToken::class)->findOneBy([]);
        if (!$token) {
            $io->error('No Sonos token in database. Go to /setup and connect Sonos first.');
            return Command::FAILURE;
        }

        $io->writeln('Token expired: ' . ($token->isExpired() ? '<error>YES</error>' : '<info>no</info>'));

        $io->section('GET /households');
        $r = $this->httpClient->request('GET', self::API . '/households', [
            'headers' => ['Authorization' => 'Bearer ' . $token->getAccessToken()],
        ]);
        $io->writeln('HTTP ' . $r->getStatusCode());
        $io->writeln($r->getContent(false));

        if ($r->getStatusCode() !== 200) {
            return Command::FAILURE;
        }

        $households = $r->toArray();
        foreach ($households['households'] ?? [] as $hh) {
            $io->section('GET /households/' . $hh['id'] . '/groups');
            $r2 = $this->httpClient->request('GET', self::API . '/households/' . $hh['id'] . '/groups', [
                'headers' => ['Authorization' => 'Bearer ' . $token->getAccessToken()],
            ]);
            $io->writeln('HTTP ' . $r2->getStatusCode());
            $io->writeln($r2->getContent(false));
        }

        return Command::SUCCESS;
    }
}
