<?php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class NewsService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $feedUrl,
    ) {}

    /** Returns up to $count headline strings from the RSS feed. */
    public function getHeadlines(int $count = 3): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->feedUrl, ['timeout' => 10]);
            $xml      = new \SimpleXMLElement($response->getContent());

            $headlines = [];
            foreach ($xml->channel->item as $item) {
                $title = trim((string) $item->title);
                if ($title !== '') {
                    $headlines[] = $title;
                }
                if (count($headlines) >= $count) {
                    break;
                }
            }

            return $headlines;
        } catch (\Throwable) {
            return [];
        }
    }
}
