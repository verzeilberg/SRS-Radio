<?php
namespace App\Service;

use App\DTO\DjContext;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DjScriptService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $groqApiKey,
    ) {}

    public function generate(DjContext $ctx): string
    {
        $prompt = $this->buildPrompt($ctx);

        $response = $this->httpClient->request(
            'POST',
            'https://api.groq.com/openai/v1/chat/completions',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->groqApiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'       => 'llama-3.3-70b-versatile',
                    'messages'    => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.9,
                ],
            ]
        );

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('OpenAI API fout (' . $response->getStatusCode() . '): ' . $response->getContent(false));
        }

        $data = $response->toArray();
        $text = trim($data['choices'][0]['message']['content'] ?? '');

        if ($text === '') {
            throw new \RuntimeException('OpenAI gaf lege tekst terug.');
        }

        return $text;
    }

    private function buildWeatherPrompt(string $base, DjContext $ctx): string
    {
        if (!$ctx->weather) {
            return "{$base}\n\nGeef een kort weerbericht voor vandaag in radio DJ stijl. Max 2-3 zinnen.";
        }

        $w = $ctx->weather;

        return "{$base}\n\n" .
            "Geef een leuk, informeel weerbericht in radio DJ stijl voor {$w['city']}:\n" .
            "- Temperatuur: {$w['temp']}°C (voelt als {$w['feels_like']}°C)\n" .
            "- Weer: {$w['description']}\n" .
            "- Luchtvochtigheid: {$w['humidity']}%\n" .
            "- Wind: {$w['wind_kmh']} km/u\n\n" .
            "Max 2-3 zinnen. Presenteer het als een echte radiopresentator, energiek maar bondig.";
    }

    private function buildPrompt(DjContext $ctx): string
    {
        $base = "Je bent een energieke radio DJ voor {$ctx->station}. Schrijf een korte radio-voice tekst (max 2-3 zinnen). Geen hashtags, geen uitleg, radio-stijl, enthousiast maar natuurlijk. Spreek de luisteraar direct aan.";

        return match ($ctx->type) {
            'morning' => "{$base}\n\nHet is {$ctx->hour}:00. Schrijf een energieke goedemorgen-introductie voor de werkdag.",

            'lunch' => "{$base}\n\nHet is lunchtijd ({$ctx->hour}:00). Schrijf een gezellige lunchbreak-aankondiging. Ontspannen toon.",

            'afternoon' => "{$base}\n\nHet is {$ctx->hour}:00, de middagsessie begint. Schrijf een motiverende introductie voor het tweede deel van de dag.",

            'friday_afternoon' => "{$base}\n\nHet is vrijdagmiddag {$ctx->hour}:00! Het weekend begint zo. Schrijf een uitbundige vrijdagmiddag-aankondiging die het weekend inluidt.",

            'end_of_day' => "{$base}\n\nHet is {$ctx->hour}:00, de werkdag zit er bijna op. Schrijf een afsluiting die de luisteraars groet en aanmoedigt.",

            'weather' => $this->buildWeatherPrompt($base, $ctx),

            default => "{$base}\n\nJe kondigt de volgende track aan die zo meteen wordt gespeeld:\nTrack: {$ctx->track}\nArtiest: {$ctx->artist}\n\nMaak er een korte, enthousiaste introductie van. Noem geen tijdstip.",
        };
    }
}
