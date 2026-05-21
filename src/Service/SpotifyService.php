<?php
namespace App\Service;

use App\Entity\SpotifyToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SpotifyService
{
    private ?string $deviceId         = null;
    private ?array  $topTrackCache    = null;
    private int     $topTrackCachedAt = 0;
    private array   $playlistCache    = [];
    private array   $playlistIdCache  = []; // name → id, cached 24h

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

    public function findPlaylistByName(string $name): ?string
    {
        if (isset($this->playlistIdCache[$name]) && (time() - $this->playlistIdCache[$name]['at']) < 86400) {
            return $this->playlistIdCache[$name]['id'];
        }

        $token = $this->getValidToken();
        if (!$token) {
            return null;
        }

        // Apostrophes cause HTTP 400 in Spotify search — strip for the query only.
        $searchQuery = str_replace("'", '', $name);

        $response = $this->httpClient->request('GET', 'https://api.spotify.com/v1/search', [
            'headers' => ['Authorization' => 'Bearer ' . $token->getAccessToken()],
            'query'   => ['q' => $searchQuery, 'type' => 'playlist', 'limit' => 10],
            'timeout' => 15,
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        foreach ($response->toArray()['playlists']['items'] ?? [] as $playlist) {
            if (!$playlist) {
                continue;
            }
            // Take the first result whose name matches exactly (case-insensitive)
            if (strcasecmp($playlist['name'] ?? '', $name) === 0) {
                $id = $playlist['id'];
                $this->playlistIdCache[$name] = ['id' => $id, 'at' => time()];
                return $id;
            }
        }

        // Fallback: return the first non-null result
        foreach ($response->toArray()['playlists']['items'] ?? [] as $playlist) {
            if ($playlist && !empty($playlist['id'])) {
                $id = $playlist['id'];
                $this->playlistIdCache[$name] = ['id' => $id, 'at' => time()];
                return $id;
            }
        }

        return null;
    }

    /** Search Spotify for playlists matching $query, returns up to $limit results as [id, name, owner, image]. */
    public function searchPlaylists(string $query, int $limit = 10): array
    {
        $token = $this->getValidToken();
        if (!$token) {
            return [];
        }

        $response = $this->httpClient->request('GET', 'https://api.spotify.com/v1/search', [
            'headers' => ['Authorization' => 'Bearer ' . $token->getAccessToken()],
            'query'   => ['q' => str_replace("'", '', $query), 'type' => 'playlist', 'limit' => $limit],
            'timeout' => 15,
        ]);

        if ($response->getStatusCode() !== 200) {
            return [];
        }

        $results = [];
        foreach ($response->toArray()['playlists']['items'] ?? [] as $item) {
            if (!$item || empty($item['id'])) {
                continue;
            }
            $results[] = [
                'id'    => $item['id'],
                'name'  => $item['name'] ?? '',
                'owner' => $item['owner']['display_name'] ?? '',
                'image' => $item['images'][0]['url'] ?? null,
            ];
        }

        return $results;
    }

    public function getPlaylistTracks(string $playlistId): array
    {
        if (isset($this->playlistCache[$playlistId]) && (time() - $this->playlistCache[$playlistId]['at']) < 300) {
            return $this->playlistCache[$playlistId]['tracks'];
        }

        $token = $this->getValidToken();
        if (!$token) {
            throw new \RuntimeException('Geen geldig Spotify token gevonden.');
        }

        try {
            $response = $this->httpClient->request('GET',
                'https://api.spotify.com/v1/playlists/' . $playlistId . '/tracks', [
                'headers' => ['Authorization' => 'Bearer ' . $token->getAccessToken()],
                'query'   => [
                    'limit'  => 100,
                    'fields' => 'items(track(id,uri,name,artists(name),album(images),duration_ms))',
                ],
                'timeout' => 30,
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException(sprintf(
                    'Playlist %s gaf HTTP %d', $playlistId, $response->getStatusCode()
                ));
            }

            $tracks = [];
            foreach ($response->toArray()['items'] ?? [] as $item) {
                $t = $item['track'] ?? null;
                if (!$t || empty($t['id'])) {
                    continue;
                }
                $tracks[] = [
                    'uri'         => $t['uri'],
                    'id'          => $t['id'],
                    'title'       => $t['name'],
                    'artist'      => $t['artists'][0]['name'] ?? '',
                    'duration_ms' => $t['duration_ms'] ?? 0,
                    'image'       => $t['album']['images'][0]['url'] ?? null,
                ];
            }

            $this->playlistCache[$playlistId] = ['at' => time(), 'tracks' => $tracks];
            return $tracks;

        } catch (\Throwable $e) {
            if (isset($this->playlistCache[$playlistId])) {
                return $this->playlistCache[$playlistId]['tracks'];
            }
            throw $e;
        }
    }

    /** Search for a track by exact title (case-insensitive). Returns Spotify URI or null. */
    public function searchTrackByTitle(string $title): ?string
    {
        $token = $this->getValidToken();
        if (!$token) {
            return null;
        }

        $response = $this->httpClient->request('GET', 'https://api.spotify.com/v1/search', [
            'headers' => ['Authorization' => 'Bearer ' . $token->getAccessToken()],
            'query'   => ['q' => $title, 'type' => 'track', 'limit' => 20],
            'timeout' => 10,
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        foreach ($response->toArray()['tracks']['items'] ?? [] as $track) {
            if (strcasecmp($track['name'] ?? '', $title) === 0) {
                return $track['uri'];
            }
        }

        return null;
    }

    /** Search for tracks, returns array of [id, uri, title, artist, image] */
    public function searchTracks(string $query, int $limit = 8): array
    {
        $token = $this->getValidToken();
        if (!$token) {
            return [];
        }

        $response = $this->httpClient->request('GET', 'https://api.spotify.com/v1/search', [
            'headers' => ['Authorization' => 'Bearer ' . $token->getAccessToken()],
            'query'   => ['q' => $query, 'type' => 'track', 'limit' => $limit],
            'timeout' => 10,
        ]);

        if ($response->getStatusCode() !== 200) {
            return [];
        }

        $results = [];
        foreach ($response->toArray()['tracks']['items'] ?? [] as $item) {
            $results[] = [
                'id'     => $item['id'],
                'uri'    => $item['uri'],
                'title'  => $item['name'],
                'artist' => implode(', ', array_column($item['artists'] ?? [], 'name')),
                'image'  => $item['album']['images'][1]['url'] ?? $item['album']['images'][0]['url'] ?? null,
            ];
        }

        return $results;
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

        $response = $this->httpClient->request('PUT',
            'https://api.spotify.com/v1/me/player/volume?volume_percent=' . $percent . $query,
            ['headers' => ['Authorization' => 'Bearer ' . $token->getAccessToken()]]
        );

        $status = $response->getStatusCode();
        if ($status === 403) {
            throw new \RuntimeException('Spotify volume control not supported on this device (device is restricted).');
        }
        if ($status >= 300) {
            throw new \RuntimeException('Spotify setVolume failed (' . $status . '): ' . $response->getContent(false));
        }
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
