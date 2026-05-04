<?php
namespace App\Controller;

use App\Entity\SpotifyToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SpotifyAuthController extends AbstractController
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $em,
        private string $clientId,
        private string $clientSecret,
        private string $redirectUri,
    ) {}

    #[Route('/spotify/connect', methods: ['GET'])]
    public function connect(): RedirectResponse
    {
        $params = http_build_query([
            'client_id'     => $this->clientId,
            'response_type' => 'code',
            'redirect_uri'  => $this->redirectUri,
            'scope'         => 'user-read-currently-playing user-read-playback-state user-top-read user-modify-playback-state streaming',
            'show_dialog'   => 'true',
        ]);

        return new RedirectResponse('https://accounts.spotify.com/authorize?' . $params);
    }

    #[Route('/spotify/callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        $code = $request->query->get('code');
        if (!$code) {
            return new Response('Missing code from Spotify.', 400);
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
            return new Response('Spotify token exchange failed: ' . $response->getContent(false), 400);
        }

        $data = $response->toArray();

        $existing = $this->em->getRepository(SpotifyToken::class)->findOneBy([]);
        if ($existing) {
            $this->em->remove($existing);
        }

        $this->em->persist(new SpotifyToken($data['access_token'], $data['refresh_token'], $data['expires_in']));
        $this->em->flush();

        return new Response('Spotify connected. The radio station can now use your account.');
    }
}
