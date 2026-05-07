<?php
namespace App\Service;

use App\DTO\DjContext;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DjScriptService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $groqApiKey,
        private string $language = 'en',
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

    private function buildNewsPrompt(string $base, DjContext $ctx): string
    {
        if (empty($ctx->headlines)) {
            return "{$base}\n\nBriefly introduce the news bulletin in radio DJ style. Max 2 sentences.";
        }

        $list = implode("\n", array_map(
            fn($i, $h) => ($i + 1) . '. ' . $h,
            range(0, count($ctx->headlines) - 1),
            $ctx->headlines,
        ));

        return "{$base}\n\nRead the following top news headlines as a natural, concise radio news bulletin. Present them fluently one after another without adding opinions or commentary:\n{$list}\n\nMax 4-5 sentences total.";
    }

    private function buildBirthdayPrompt(string $base, DjContext $ctx): string
    {
        $name = $ctx->birthdayColleague ?? 'a colleague';
        return "{$base}\n\nAnnounce on-air that today is {$name}'s birthday! Wish them a happy birthday on behalf of the whole team. Keep it warm, fun, and festive — radio style, max 2-3 sentences.";
    }

    private function buildSongFactPrompt(string $base, DjContext $ctx): string
    {
        return "{$base}\n\nShare one interesting, surprising, or little-known fact about {$ctx->artist} or their music with the listeners. Keep it conversational and radio-style, max 2 sentences. Vary your opening — don't always start with \"Did you know\".";
    }

    private function buildWeatherPrompt(string $base, DjContext $ctx): string
    {
        if (!$ctx->weather) {
            return "{$base}\n\nGive a short weather update for today in radio DJ style. Max 2-3 sentences.";
        }

        $w = $ctx->weather;

        return "{$base}\n\n" .
            "Give a fun, informal weather update in radio DJ style for {$w['city']}:\n" .
            "- Temperature: {$w['temp']}°C (feels like {$w['feels_like']}°C)\n" .
            "- Weather: {$w['description']}\n" .
            "- Humidity: {$w['humidity']}%\n" .
            "- Wind: {$w['wind_kmh']} km/h\n\n" .
            "Max 2-3 sentences. Present it as a real radio host, energetic but concise.";
    }

    private function buildPrompt(DjContext $ctx): string
    {
        $lang = $this->language === 'nl' ? 'Dutch' : 'English';
        $base = "You are an energetic radio DJ for {$ctx->station}. Write a short radio voice-over text (max 2-3 sentences). No hashtags, no explanations, radio style, enthusiastic but natural. Address the listener directly. Respond in {$lang}.";

        if (!empty($ctx->recentTexts)) {
            $list  = implode("\n", array_map(fn($t) => '- ' . $t, $ctx->recentTexts));
            $base .= "\n\nThese clips were already played recently — do NOT repeat or closely paraphrase any of them:\n{$list}";
        }

        return match ($ctx->type) {
            'morning' => "{$base}\n\nIt is {$ctx->hour}:00. Write an energetic good morning introduction for the workday.",

            'lunch' => "{$base}\n\nIt is lunchtime ({$ctx->hour}:00). Write a friendly lunch break announcement. Relaxed tone.",

            'afternoon' => "{$base}\n\nIt is {$ctx->hour}:00, the afternoon session begins. Write a motivating introduction for the second part of the day.",

            'friday_afternoon' => "{$base}\n\nIt is Friday afternoon at {$ctx->hour}:00! The weekend is almost here. Write an enthusiastic Friday afternoon announcement that kicks off the weekend.",

            'end_of_day' => "{$base}\n\nIt is {$ctx->hour}:00, the workday is almost over. Write a closing that greets and encourages the listeners.",

            'weather' => $this->buildWeatherPrompt($base, $ctx),

            'news' => $this->buildNewsPrompt($base, $ctx),

            'birthday'  => $this->buildBirthdayPrompt($base, $ctx),

            'song_fact' => $this->buildSongFactPrompt($base, $ctx),

            default => "{$base}\n\nYou are introducing the next track about to be played:\nTrack: {$ctx->track}\nArtist: {$ctx->artist}\n\nMake a short, enthusiastic introduction. Do not mention a time.",
        };
    }
}
