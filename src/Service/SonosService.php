<?php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class SonosService
{
    private ?int    $spotifySerialNum     = null;
    private ?int    $spotifyServiceId     = null;
    private ?string $avTransportUrl       = null;
    private ?string $renderingControlUrl  = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $sonosIp,
        private int $spotifySnOverride = 0,
    ) {}

    public function getIp(): string
    {
        return $this->sonosIp;
    }

    public function setIp(string $ip): void
    {
        $this->sonosIp = $ip;
    }

    /**
     * Probe $ip for a DLNA/UPnP MediaRenderer, discover its AVTransport and
     * RenderingControl endpoints, and configure this service to use them.
     */
    public function discoverDlnaDevice(string $ip): bool
    {
        $candidates = [
            "http://{$ip}:9197/dmr",
            "http://{$ip}:1400/xml/device_description.xml",
            "http://{$ip}:7676/dmr",
            "http://{$ip}:8080/description.xml",
        ];

        foreach ($candidates as $descUrl) {
            try {
                $response = $this->httpClient->request('GET', $descUrl, ['timeout' => 2]);
                if ($response->getStatusCode() !== 200) {
                    continue;
                }

                $xml    = new \SimpleXMLElement($response->getContent());
                $avPath = null;
                $rcPath = null;

                foreach ($xml->xpath('//*[local-name()="service"]') as $service) {
                    $type = (string) ($service->xpath('*[local-name()="serviceType"]')[0] ?? '');
                    $ctrl = (string) ($service->xpath('*[local-name()="controlURL"]')[0] ?? '');

                    if ($type === 'urn:schemas-upnp-org:service:AVTransport:1') {
                        $avPath = $ctrl;
                    } elseif ($type === 'urn:schemas-upnp-org:service:RenderingControl:1') {
                        $rcPath = $ctrl;
                    }
                }

                if ($avPath !== null) {
                    $parsed = parse_url($descUrl);
                    $base   = $parsed['scheme'] . '://' . $parsed['host'] . ':' . $parsed['port'];
                    $this->avTransportUrl      = $base . $avPath;
                    $this->renderingControlUrl = $rcPath !== null ? $base . $rcPath : null;
                    $this->sonosIp             = $ip;
                    return true;
                }
            } catch (\Throwable) {}
        }

        return false;
    }

    public function findDeviceIp(string $roomName): ?string
    {
        try {
            ['body' => $body] = $this->getTopologyRaw();
            $xml = new \SimpleXMLElement($body);
            foreach ($xml->ZonePlayers->ZonePlayer ?? [] as $player) {
                $name     = (string) $player['roomName'];
                $location = (string) $player['LOCATION'];
                if (stripos($name, $roomName) !== false && preg_match('/http:\/\/([^:]+):/', $location, $m)) {
                    return $m[1];
                }
            }
        } catch (\Throwable) {}

        return null;
    }

    public function getRoomName(): string
    {
        $response = $this->httpClient->request('GET', "http://{$this->sonosIp}:1400/xml/device_description.xml");
        preg_match('/<roomName>(.*?)<\/roomName>/', $response->getContent(), $m);
        return $m[1] ?? '';
    }

    public function getAccountsRaw(): array
    {
        $response = $this->httpClient->request('GET', "http://{$this->sonosIp}:1400/status/accounts");
        return [
            'status' => $response->getStatusCode(),
            'body'   => $response->getContent(false),
        ];
    }

    public function getMusicServicesRaw(): array
    {
        $envelope = <<<XML
<?xml version="1.0"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
  <s:Body>
    <u:ListAvailableServices xmlns:u="urn:schemas-upnp-org:service:MusicServices:1"/>
  </s:Body>
</s:Envelope>
XML;
        $response = $this->httpClient->request('POST', "http://{$this->sonosIp}:1400/MusicServices/Control", [
            'headers' => [
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPACTION'   => '"urn:schemas-upnp-org:service:MusicServices:1#ListAvailableServices"',
            ],
            'body' => $envelope,
        ]);
        return [
            'status' => $response->getStatusCode(),
            'body'   => $response->getContent(false),
        ];
    }

    public function getTopologyRaw(): array
    {
        $response = $this->httpClient->request('GET', "http://{$this->sonosIp}:1400/status/topology");
        return [
            'status' => $response->getStatusCode(),
            'body'   => $response->getContent(false),
        ];
    }

    public function getAccounts(): array
    {
        ['status' => $status, 'body' => $body] = $this->getAccountsRaw();

        if ($status !== 200 || $body === '') {
            return [];
        }

        $accounts = [];
        try {
            $xml = new \SimpleXMLElement($body);
            foreach ($xml->Accounts->Account ?? [] as $account) {
                $accounts[] = [
                    'type'       => (string) $account['Type'],
                    'serial_num' => (int)    $account['SerialNum'],
                    'username'   => (string) ($account->UN ?? ''),
                ];
            }
        } catch (\Throwable) {}

        return $accounts;
    }

    public function playHttpClip(string $url): void
    {
        $ext      = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $mimeMap  = ['mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'aac' => 'audio/aac'];
        $mime     = $mimeMap[$ext] ?? 'audio/mpeg';
        $metadata = '<DIDL-Lite xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/" xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/"><item id="1" parentID="0" restricted="false"><res protocolInfo="http-get:*:' . $mime . ':*">' . htmlspecialchars($url, ENT_XML1) . '</res><dc:title>Alarm</dc:title><upnp:class>object.item.audioItem</upnp:class></item></DIDL-Lite>';

        $this->soap('SetAVTransportURI', [
            'InstanceID'         => 0,
            'CurrentURI'         => $url,
            'CurrentURIMetaData' => $metadata,
        ]);

        $this->soap('Play', ['InstanceID' => 0, 'Speed' => 1]);
    }

    public function play(string $spotifyTrackId, string $title, string $artist): void
    {
        $sn  = $this->resolveSpotifySerialNum();
        $sid = $this->resolveSpotifyServiceId();
        $uri = 'x-sonos-spotify:spotify%3atrack%3a' . $spotifyTrackId . '?sid=' . $sid . '&flags=8232&sn=' . $sn;
        $metadata = $this->buildMetadata($spotifyTrackId, $title, $artist, $sn, $sid);

        $this->soap('SetAVTransportURI', [
            'InstanceID'         => 0,
            'CurrentURI'         => $uri,
            'CurrentURIMetaData' => $metadata,
        ]);

        $this->soap('Play', [
            'InstanceID' => 0,
            'Speed'      => 1,
        ]);
    }

    public function setNextTrack(string $spotifyTrackId, string $title, string $artist): void
    {
        $sn  = $this->resolveSpotifySerialNum();
        $sid = $this->resolveSpotifyServiceId();
        $uri = 'x-sonos-spotify:spotify%3atrack%3a' . $spotifyTrackId . '?sid=' . $sid . '&flags=8232&sn=' . $sn;
        $metadata = $this->buildMetadata($spotifyTrackId, $title, $artist, $sn, $sid);

        $this->soap('SetNextAVTransportURI', [
            'InstanceID'      => 0,
            'NextURI'         => $uri,
            'NextURIMetaData' => $metadata,
        ]);
    }

    public function getVolume(): int
    {
        $body = $this->soapRC('GetVolume', ['InstanceID' => 0, 'Channel' => 'Master']);
        preg_match('/<CurrentVolume>(\d+)<\/CurrentVolume>/', $body, $m);
        return (int) ($m[1] ?? 0);
    }

    public function setVolume(int $volume): void
    {
        $this->soapRC('SetVolume', [
            'InstanceID'    => 0,
            'Channel'       => 'Master',
            'DesiredVolume' => max(0, min(100, $volume)),
        ]);
    }

    public function stop(): void
    {
        $this->soap('Stop', ['InstanceID' => 0]);
    }

    public function isPlaying(): bool
    {
        $response = $this->soap('GetTransportInfo', ['InstanceID' => 0]);
        // TRANSITIONING = buffering/seeking — treat as active, not stopped
        return str_contains($response, '<CurrentTransportState>PLAYING</CurrentTransportState>')
            || str_contains($response, '<CurrentTransportState>TRANSITIONING</CurrentTransportState>');
    }

    public function seek(int $seconds): void
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        $this->soap('Seek', [
            'InstanceID' => 0,
            'Unit'       => 'REL_TIME',
            'Target'     => sprintf('%d:%02d:%02d', $h, $m, $s),
        ]);
    }

    public function waitForClipToEnd(int $maxSeconds = 120): void
    {
        sleep(2);
        $elapsed = 2;
        while ($elapsed < $maxSeconds) {
            if (!$this->isPlaying()) {
                return;
            }
            sleep(2);
            $elapsed += 2;
        }
    }

    public function getPositionInfoRaw(): string
    {
        try {
            return $this->soap('GetPositionInfo', ['InstanceID' => 0]);
        } catch (\Throwable) {
            return '';
        }
    }

    public function getPositionInfo(): array
    {
        $body = $this->soap('GetPositionInfo', ['InstanceID' => 0]);

        preg_match('/<TrackDuration>(.*?)<\/TrackDuration>/', $body, $duration);
        preg_match('/<RelTime>(.*?)<\/RelTime>/', $body, $position);

        return [
            'duration' => $this->timeToSeconds($duration[1] ?? '0:00:00'),
            'position' => $this->timeToSeconds($position[1] ?? '0:00:00'),
        ];
    }

    private function resolveSpotifySerialNum(): int
    {
        if ($this->spotifySerialNum !== null) {
            return $this->spotifySerialNum;
        }

        if ($this->spotifySnOverride > 0) {
            return $this->spotifySerialNum = $this->spotifySnOverride;
        }

        foreach ($this->getAccounts() as $account) {
            if ($account['type'] === '2311') {
                return $this->spotifySerialNum = $account['serial_num'];
            }
        }

        return $this->spotifySerialNum = 1;
    }

    private function resolveSpotifyServiceId(): int
    {
        if ($this->spotifyServiceId !== null) {
            return $this->spotifyServiceId;
        }

        try {
            $raw = $this->getMusicServicesRaw();
            // The descriptor list is HTML-entity-encoded; extract Spotify's Id with a simple regex
            if (preg_match('/&lt;Service Id=&quot;(\d+)&quot; Name=&quot;Spotify&quot;/', $raw['body'], $m)) {
                return $this->spotifyServiceId = (int) $m[1];
            }
        } catch (\Throwable) {}

        return $this->spotifyServiceId = 9; // default for newer Sonos S2 firmware
    }

    private function timeToSeconds(string $time): int
    {
        $parts = explode(':', $time);
        return (int)($parts[0] ?? 0) * 3600 + (int)($parts[1] ?? 0) * 60 + (int)($parts[2] ?? 0);
    }

    private function soapRC(string $action, array $params): string
    {
        $body = '';
        foreach ($params as $key => $value) {
            $body .= '<' . $key . '>' . htmlspecialchars((string) $value, ENT_XML1) . '</' . $key . '>';
        }

        $envelope = <<<XML
<?xml version="1.0"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
  <s:Body>
    <u:{$action} xmlns:u="urn:schemas-upnp-org:service:RenderingControl:1">{$body}</u:{$action}>
  </s:Body>
</s:Envelope>
XML;

        $response = $this->httpClient->request('POST', $this->getRenderingControlUrl(), [
            'headers' => [
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPACTION'   => '"urn:schemas-upnp-org:service:RenderingControl:1#' . $action . '"',
            ],
            'body' => $envelope,
        ]);

        $body = $response->getContent(false);

        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException(sprintf(
                'Sonos RenderingControl %s mislukt (HTTP %d): %s',
                $action,
                $response->getStatusCode(),
                substr(strip_tags($body), 0, 200)
            ));
        }

        return $body;
    }

    private function soap(string $action, array $params): string
    {
        $body = '';
        foreach ($params as $key => $value) {
            $body .= '<' . $key . '>' . htmlspecialchars((string) $value, ENT_XML1) . '</' . $key . '>';
        }

        $envelope = <<<XML
<?xml version="1.0"?>
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
  <s:Body>
    <u:{$action} xmlns:u="urn:schemas-upnp-org:service:AVTransport:1">{$body}</u:{$action}>
  </s:Body>
</s:Envelope>
XML;

        $response = $this->httpClient->request('POST', $this->getAvTransportUrl(), [
            'headers' => [
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPACTION'   => '"urn:schemas-upnp-org:service:AVTransport:1#' . $action . '"',
            ],
            'body' => $envelope,
        ]);

        $body = $response->getContent(false);

        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException(sprintf(
                'Sonos UPnP %s mislukt (HTTP %d): %s',
                $action,
                $response->getStatusCode(),
                substr(strip_tags($body), 0, 200)
            ));
        }

        return $body;
    }

    private function getAvTransportUrl(): string
    {
        return $this->avTransportUrl ?? "http://{$this->sonosIp}:1400/MediaRenderer/AVTransport/Control";
    }

    private function getRenderingControlUrl(): string
    {
        return $this->renderingControlUrl ?? "http://{$this->sonosIp}:1400/MediaRenderer/RenderingControl/Control";
    }

    private function buildMetadata(string $id, string $title, string $artist, int $sn = 1, int $sid = 9): string
    {
        $uri    = 'x-sonos-spotify:spotify%3atrack%3a' . $id . '?sid=' . $sid . '&amp;flags=8232&amp;sn=' . $sn;
        $title  = htmlspecialchars($title,  ENT_XML1);
        $artist = htmlspecialchars($artist, ENT_XML1);

        return <<<XML
<DIDL-Lite xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:upnp="urn:schemas-upnp-org:metadata-1-0/upnp/" xmlns:r="urn:schemas-rinconnetworks-com:metadata-1-0/" xmlns="urn:schemas-upnp-org:metadata-1-0/DIDL-Lite/">
  <item id="00032020spotify%3atrack%3a{$id}" restricted="true" parentID="00020000spotify%3auser%3a">
    <res protocolInfo="sonos.com-spotify:*:audio/x-spotify:*">{$uri}</res>
    <r:streamContent/>
    <dc:title>{$title}</dc:title>
    <upnp:class>object.item.audioItem.musicTrack</upnp:class>
    <dc:creator>{$artist}</dc:creator>
    <upnp:album/>
  </item>
</DIDL-Lite>
XML;
    }
}
