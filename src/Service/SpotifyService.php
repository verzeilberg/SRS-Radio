<?php
namespace App\Service;

use App\Entity\SpotifyToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SpotifyService
{
    private ?string $deviceId    = null;
    private ?array  $topTrackCache = null;
    private int     $topTrackCachedAt = 0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $em,
        private string $clientId,
        private string $clientSecret,
    ) {}

    public function setDeviceId(string $deviceId): void
    {
        $this->deviceId = $deviceId;
    }

    public function getCurrentTrack(): array
    {
        $token = $this->getValidToken();
        if (!$token) {
            return [];
        }

        $response = $this->httpClient->request('GET', 'https://api.spotify.com/v1/me/player/currently-playing', [
            'headers' => ['Authorization' => 'Bearer ' . $token->getAccessToken()],
        ]);

        if ($response->getStatusCode() !== 200) {
            return [];
        }

        $data = $response->toArray();

        return [
            'track'      => $data['item']['name'] ?? '',
            'artist'     => $data['item']['artists'][0]['name'] ?? '',
            'spotify_id' => $data['item']['id'] ?? null,
        ];
    }

    public function getTopTracks(string $timeRange = 'medium_term', int $limit = 50): array
    {
        // Cache for 5 minutes — the list barely changes between songs.
        if ($this->topTrackCache !== null && (time() - $this->topTrackCachedAt) < 300) {
            return $this->topTrackCache;
        }

        $token = $this->getValidToken();
        if (!$token) {
            throw new \RuntimeException('Geen geldig Spotify token gevonden in de database.');
        }

        try {
            $response = $this->httpClient->request('GET', 'https://api.spotify.com/v1/me/top/tracks', [
                'headers' => ['Authorization' => 'Bearer ' . $token->getAccessToken()],
                'query'   => ['time_range' => $timeRange, 'limit' => $limit],
                'timeout' => 30,
            ]);

            $status = $response->getStatusCode();
            if ($status !== 200) {
                throw new \RuntimeException(sprintf(
                    'Spotify top tracks API gaf HTTP %d: %s',
                    $status,
                    $response->getContent(false)
                ));
            }

            $tracks = array_map(fn($item) => [
                'uri'         => $item['uri'],
                'id'          => $item['id'],
                'title'       => $item['name'],
                'artist'      => $item['artists'][0]['name'] ?? '',
                'duration_ms' => $item['duration_ms'] ?? 0,
                'image'       => $item['album']['images'][0]['url'] ?? null,
            ], $response->toArray()['items'] ?? []);

            $this->topTrackCache    = $tracks;
            $this->topTrackCachedAt = time();

            return $tracks;
        } catch (\Throwable $e) {
            // On any failure, return stale cache if available so the radio keeps running.
            if ($this->topTrackCache !== null) {
                return $this->topTrackCache;
            }
            throw $e;
        }
    }

    public function getAccessToken(): ?string
    {
        return $this->getValidToken()?->getAccessToken();
    }

    public function getDevices(): array
    {
        $token = $this->getValidToken();
        if (!$token) {
            return [];
        }

        $response = $this->httpClient->request('GET', 'https://api.spotify.com/v1/me/player/devices', [
            'headers' => ['Authorization' => 'Bearer ' . $token->getAccessToken()],
        ]);

        return $response->toArray()['devices'] ?? [];
    }

    public function findDeviceByName(string $name): ?string
    {
        foreach ($this->getDevices() as $device) {
            if (stripos($device['name'], $name) !== false) {
                return $device['id'];
            }
        }

        return null;
    }

    public function findSpeakerDevice(string $preferredName = ''): ?string
    {
        $devices = $this->getDevices();

        if ($preferredName !== '') {
            foreach ($devices as $device) {
                if (stripos($device['name'], $preferredName) !== false) {
                    return $device['id'];
                }
            }
        }

        foreach ($devices as $device) {
            if (strtolower($device['type']) === 'speaker') {
                return $device['id'];
            }
        }

        return null;
    }

    public function pause(): void
    {
        $token = $this->getValidToken();
        if (!$token) {
            return;
        }

        $query = $this->deviceId ? '?device_id=' . $this->deviceId : '';

        $this->httpClient->request('PUT', 'https://api.spotify.com/v1/me/player/pause' . $query, [
            'headers' => ['Authorization' => 'Bearer ' . $token->getAccessToken()],
        ]);
    }

    public function resume(): void
    {
        $token = $this->getValidToken();
        if (!$token) {
            return;
        }

        $query = $this->deviceId ? '?device_id=' . $this->deviceId : '';

        $this->httpClient->request('PUT', 'https://api.spotify.com/v1/me/player/play' . $query, [
            'headers' => ['Authorization' => 'Bearer ' . $token->getAccessToken()],
        ]);
    }

    public function playTrack(string $spotifyUri): bool
    {
        $token = $this->getValidToken();
        if (!$token) {
            return false;
        }

        $query = $this->deviceId ? '?device_id=' . $this->deviceId : '';

        $response = $this->httpClient->request('PUT', 'https://api.spotify.com/v1/me/player/play' . $query, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token->getAccessToken(),
                'Content-Type'  => 'application/json',
            ],
            'json' => ['uris' => [$spotifyUri]],
        ]);

        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException(sprintf(
                'Spotify playTrack failed (%d): %s',
                $response->getStatusCode(),
                $response->getContent(false)
            ));
        }

        return true;
    }

    public function getCurrentPlayback(): array
    {
        $token = $this->getValidToken();
        if (!$token) {
            return [];
        }

        try {
            $response = $this->httpClient->request('GET', 'https://api.spotify.com/v1/me/player', [
                'headers' => ['Authorization' => 'Bearer ' . $token->getAccessToken()],
                'timeout' => 15,
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $data = $response->toArray();

            return [
                'is_playing'     => $data['is_playing'] ?? false,
                'spotify_id'     => $data['item']['id'] ?? null,
                'progress_ms'    => $data['progress_ms'] ?? 0,
                'duration_ms'    => $data['item']['duration_ms'] ?? 0,
                'device_name'    => $data['device']['name'] ?? null,
                'device_id'      => $data['device']['id'] ?? null,
                'album_image'    => $data['item']['album']['images'][0]['url'] ?? null,
                'volume_percent' => $data['device']['volume_percent'] ?? 50,
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    public function setVolume(int $percent): void
    {
        $token = $this->getValidToken();
        if (!$token) {
            return;
        }

        $query = $this->deviceId ? '&device_id=' . $this->deviceId : '';

        $this->httpClient->request('PUT',
            'https://api.spotify.com/v1/me/player/volume?volume_percent=' . $percent . $query,
            ['headers' => ['Authorization' => 'Bearer ' . $token->getAccessToken()]]
        );
    }

    private function getValidToken(): ?SpotifyToken
    {
        $token = $this->em->getRepository(SpotifyToken::class)->findOneBy([]);
        if (!$token) {
            return null;
        }

        if ($token->isExpired()) {
            $this->refresh($token);
        }

        return $token;
    }

    private function refresh(SpotifyToken $token): void
    {
        $response = $this->httpClient->request('POST', 'https://accounts.spotify.com/api/token', [
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
