<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class TeamsNotificationService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $webhookUrl,
    ) {}

    public function sendMessage(string $message, string $title = 'SRS Radio'): bool
    {
        $isPowerAutomate = str_contains($this->webhookUrl, 'powerautomate') || str_contains($this->webhookUrl, 'powerplatform');

        if ($isPowerAutomate) {
            $payload = [
                'title' => $title,
                'message' => $message,
                'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Amsterdam')))->format('c'),
            ];
        } else {
            $payload = [
                '@type' => 'MessageCard',
                '@context' => 'http://schema.org/extensions',
                'themeColor' => '0076D7',
                'summary' => $title,
                'sections' => [
                    [
                        'activityTitle' => $title,
                        'activitySubtitle' => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Amsterdam')))->format('l, F j, Y'),
                        'text' => $message,
                        'markdown' => true,
                    ],
                ],
            ];
        }

        try {
            $response = $this->httpClient->request('POST', $this->webhookUrl, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            return $response->getStatusCode() === 200 || $response->getStatusCode() === 202;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function sendAdaptiveCard(array $card): bool
    {
        $payload = [
            'type' => 'message',
            'attachments' => [
                [
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'content' => $card,
                ],
            ],
        ];

        try {
            $response = $this->httpClient->request('POST', $this->webhookUrl, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            return $response->getStatusCode() === 200;
        } catch (\Throwable $e) {
            return false;
        }
    }
}