<?php
namespace App\Command;

use App\Entity\SpotifyToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'radio:spotify:login', description: 'Authenticate with Spotify via the console')]
class SpotifyLoginCommand extends Command
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $em,
        private string $clientId,
        private string $clientSecret,
        private string $redirectUri,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $existing = $this->em->getRepository(SpotifyToken::class)->findOneBy([]);
        if ($existing) {
            $io->warning('A Spotify token already exists. This will replace it.');
            if (!$io->confirm('Continue?', false)) {
                return Command::SUCCESS;
            }
        }

        $params = http_build_query([
            'client_id'     => $this->clientId,
            'response_type' => 'code',
            'redirect_uri'  => $this->redirectUri,
            'scope'         => 'user-read-currently-playing user-read-playback-state user-top-read user-modify-playback-state streaming',
            'show_dialog'   => 'true',
        ]);

        $authUrl = 'https://accounts.spotify.com/authorize?' . $params;

        $io->writeln('Open this URL in your browser and authorize the application:');
        $io->writeln('');
        $io->writeln("  <href=$authUrl>$authUrl</>");
        $io->writeln('');
        $io->writeln('After authorizing, you will be redirected to a URL. Copy the value of the <comment>code</comment> parameter from that URL and paste it below.');
        $io->writeln('(The URL looks like: <comment>' . $this->redirectUri . '?code=...&state=...</comment>)');
        $io->writeln('');

        $code = $io->ask('Enter the authorization code');

        if (!$code) {
            $io->error('No code provided.');
            return Command::FAILURE;
        }

        $response = $this->httpClient->request('POST', 'https://accounts.spotify.com/api/token', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type'   => 'authorization_code',
                'code'         => $code,
                'redirect_uri' => $this->redirectUri,
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            $io->error('Spotify token exchange failed: ' . $response->getContent(false));
            return Command::FAILURE;
        }

        $data = $response->toArray();

        if ($existing) {
            $this->em->remove($existing);
        }

        $this->em->persist(new SpotifyToken($data['access_token'], $data['refresh_token'], $data['expires_in']));
        $this->em->flush();

        $io->success('Spotify connected successfully!');

        return Command::SUCCESS;
    }
}
