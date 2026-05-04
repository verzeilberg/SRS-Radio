<?php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class WeatherService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey,
        private string $location,
    ) {}

    public function getCurrent(): ?array
    {
        if ($this->apiKey === '' || $this->location === '') {
            return null;
        }

        $response = $this->httpClient->request('GET', 'https://api.openweathermap.org/data/2.5/weather', [
            'query' => [
                'q'     => $this->location,
                'appid' => $this->apiKey,
                'units' => 'metric',
                'lang'  => 'nl',
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->toArray();

        return [
            'city'        => $data['name'],
            'description' => $data['weather'][0]['description'] ?? '',
            'temp'        => (int) round($data['main']['temp']),
            'feels_like'  => (int) round($data['main']['feels_like']),
            'humidity'    => (int) $data['main']['humidity'],
            'wind_kmh'    => (int) round(($data['wind']['speed'] ?? 0) * 3.6),
        ];
    }
}
