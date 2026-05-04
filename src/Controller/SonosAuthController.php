<?php
namespace App\Controller;

use App\Entity\SonosToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SonosAuthController extends AbstractController
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $em,
        private string $clientId,
        private string $clientSecret,
        private string $redirectUri,
    ) {}

    #[Route('/sonos/connect', methods: ['GET'])]
    public function connect(): RedirectResponse
    {
        $params = http_build_query([
            'client_id'     => $this->clientId,
            'response_type' => 'code',
            'redirect_uri'  => $this->redirectUri,
            'scope'         => 'playback-control-all',
            'state'         => bin2hex(random_bytes(8)),
        ]);

        return new RedirectResponse('https://api.sonos.com/login/v3/oauth?' . $params);
    }

    #[Route('/sonos/debug', methods: ['GET'])]
    public function debug(): Response
    {
        $verifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $params = http_build_query([
            'client_id'             => $this->clientId,
            'response_type'         => 'code',
            'redirect_uri'          => $this->redirectUri,
            'state'                 => 'test123',
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        $url = 'https://api.sonos.com/login/v3/oauth?' . $params;

        return new Response(
            '<pre>'
            . 'client_id:    ' . htmlspecialchars($this->clientId) . "\n"
            . 'redirect_uri: ' . htmlspecialchars($this->redirectUri) . "\n\n"
            . 'Full URL: <a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($url) . '</a>'
            . '</pre>'
        );
    }

    #[Route('/sonos/callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        $code     = $request->query->get('code');
        $verifier = $request->getSession()->get('sonos_verifier');

        if (!$code) {
            return new Response('Missing code from Sonos. Error: ' . $request->query->get('error', 'unknown'), 400);
        }

        $body = [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $this->redirectUri,
        ];

        if ($verifier) {
            $body['code_verifier'] = $verifier;
        }

        $response = $this->httpClient->request('POST', 'https://api.sonos.com/login/v3/oauth/access', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => $body,
        ]);

        if ($response->getStatusCode() !== 200) {
            return new Response('Sonos token exchange failed: ' . $response->getContent(false), 400);
        }

        $data = $response->toArray();

        $existing = $this->em->getRepository(SonosToken::class)->findOneBy([]);
        if ($existing) {
            $this->em->remove($existing);
        }

        $this->em->persist(new SonosToken($data['access_token'], $data['refresh_token'], $data['expires_in']));
        $this->em->flush();

        return new Response('Sonos verbonden. De radio kan nu PHPSD aansturen.');
    }
}
