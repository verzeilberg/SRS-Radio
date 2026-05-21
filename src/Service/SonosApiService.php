<?php
namespace App\Service;

use App\Entity\SonosToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SonosApiService
{
    private const API = 'https://api.ws.sonos.com/control/api/v1';

    private ?string $groupId  = null;
    private ?string $playerId = null;
    private ?string $playerIp = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $em,
        private string $clientId,
        private string $clientSecret,
    ) {}

    /**
     * Find and cache the group ID for the given room name.
     * Returns null if the room is not found or no token is available.
     */
    public function discoverGroup(string $roomName): ?string
    {
        $token = $this->getValidToken();
        if (!$token) {
            return null;
        }

        $households = $this->get('/households', $token);
        foreach ($households['households'] ?? [] as $household) {
            $groups = $this->get('/households/' . $household['id'] . '/groups', $token);
            foreach ($groups['players'] ?? [] as $player) {
                if (stripos($player['name'], $roomName) !== false) {
                    // Find which group this player belongs to
                    foreach ($groups['groups'] ?? [] as $group) {
                        if (in_array($player['id'], $group['playerIds'] ?? [], true)) {
                            $this->groupId  = $group['id'];
                            $this->playerId = $player['id'];
                            // Extract IP from websocketUrl (wss://192.168.x.x:1443/...)
                            if (preg_match('/wss?:\/\/([^:\/?]+)/', $player['websocketUrl'] ?? '', $m)) {
                                $this->playerIp = $m[1];
                            }
                            return $this->groupId;
                        }
                    }
                }
            }
        }

        return null;
    }

    public function getGroupId(): ?string
    {
        return $this->groupId;
    }

    public function getPlayerIp(): ?string
    {
        return $this->playerIp;
    }

    /**
     * Play a short audio clip on the Sonos speaker (overlays current music).
     * $clipUrl must be an HTTP URL reachable by the Sonos speaker on the LAN.
     */
    public function playAudioClip(string $clipUrl, ?int $volume = null): bool
    {
        $token = $this->getValidToken();
        if (!$token || !$this->playerId) {
            return false;
        }

        $volume ??= $this->getGroupVolume() ?? 40;

        $response = $this->httpClient->request(
            'POST',
            self::API . '/players/' . $this->playerId . '/audioClip',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token->getAccessToken(),
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'name'      => 'SRS FM DJ',
                    'appId'     => 'com.srs.radio',
                    'streamUrl' => $clipUrl,
                    'volume'    => $volume,
                    'priority'  => 'HIGH',
                    'clipType'  => 'CUSTOM',
                ],
            ]
        );

        $status = $response->getStatusCode();
        if ($status >= 300) {
            error_log('Sonos audioClip failed (' . $status . '): ' . $response->getContent(false));
        }
        return $status < 300;
    }

    public function getGroupVolume(): ?int
    {
        $token = $this->getValidToken();
        if (!$token || !$this->groupId) {
            return null;
        }

        $data = $this->get('/groups/' . $this->groupId . '/groupVolume', $token);

        return isset($data['volume']) ? (int) $data['volume'] : null;
    }

    public function setGroupVolume(int $volume): bool
    {
        $token = $this->getValidToken();
        if (!$token || !$this->groupId) {
            return false;
        }

        $response = $this->httpClient->request(
            'POST',
            self::API . '/groups/' . $this->groupId . '/groupVolume',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token->getAccessToken(),
                    'Content-Type'  => 'application/json',
                ],
                'json' => ['volume' => max(0, min(100, $volume))],
            ]
        );

        return $response->getStatusCode() < 300;
    }

    public function pause(): bool
    {
        $token = $this->getValidToken();
        if (!$token || !$this->groupId) {
            return false;
        }

        $response = $this->httpClient->request(
            'POST',
            self::API . '/groups/' . $this->groupId . '/playback/pause',
            ['headers' => ['Authorization' => 'Bearer ' . $token->getAccessToken()]]
        );

        return $response->getStatusCode() < 300;
    }

    public function resume(): bool
    {
        $token = $this->getValidToken();
        if (!$token || !$this->groupId) {
            return false;
        }

        $response = $this->httpClient->request(
            'POST',
            self::API . '/groups/' . $this->groupId . '/playback/play',
            ['headers' => ['Authorization' => 'Bearer ' . $token->getAccessToken()]]
        );

        return $response->getStatusCode() < 300;
    }

    /**
     * Play a Spotify track on the group.
     * Uses loadStreamUrl which accepts Spotify URIs when Spotify is linked to the Sonos account.
     */
    public function playSpotifyTrack(string $spotifyUri, string $title = '', string $artist = ''): bool
    {
        $token = $this->getValidToken();
        if (!$token || !$this->groupId) {
            return false;
        }

        $trackId = str_replace('spotify:track:', '', $spotifyUri);

        $response = $this->httpClient->request(
            'POST',
            self::API . '/groups/' . $this->groupId . '/playback/loadStreamUrl',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token->getAccessToken(),
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'streamUrl'       => $spotifyUri,
                    'itemId'          => $spotifyUri,
                    'stationMetadata' => [
                        'name'        => $title ?: 'SRS FM',
                        'description' => $artist,
                    ],
                ],
            ]
        );

        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException(sprintf(
                'Sonos API playSpotifyTrack failed (%d): %s',
                $response->getStatusCode(),
                $response->getContent(false)
            ));
        }

        return true;
    }

    /**
     * Get current playback state for the group.
     */
    public function getPlayback(): array
    {
        $token = $this->getValidToken();
        if (!$token || !$this->groupId) {
            return [];
        }

        $data = $this->get('/groups/' . $this->groupId . '/playback', $token);

        return [
            'is_playing'  => ($data['playbackState'] ?? '') === 'PLAYBACK_STATE_PLAYING',
            'progress_ms' => (int) ($data['positionMillis'] ?? 0),
            'duration_ms' => (int) ($data['itemDurationMillis'] ?? 0),
        ];
    }

    private function get(string $path, SonosToken $token): array
    {
        $response = $this->httpClient->request('GET', self::API . $path, [
            'headers' => ['Authorization' => 'Bearer ' . $token->getAccessToken()],
        ]);

        if ($response->getStatusCode() !== 200) {
            return [];
        }

        return $response->toArray();
    }

    private function getValidToken(): ?SonosToken
    {
        $token = $this->em->getRepository(SonosToken::class)->findOneBy([]);
        if (!$token) {
            return null;
        }

        if ($token->isExpired()) {
            $this->refresh($token);
        }

        return $token;
    }

    private function refresh(SonosToken $token): void
    {
        $response = $this->httpClient->request('POST', 'https://api.sonos.com/login/v3/oauth/access', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $token->getRefreshToken(),
            ],
        ]);

        $data = $response->toArray();
        $token->update($data['access_token'], $data['expires_in']);
        $this->em->flush();
    }
}
