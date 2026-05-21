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
                    'temperature' => 1.1,
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

    private function buildSongRequestPrompt(string $base, DjContext $ctx): string
    {
        $from  = $ctx->requesterName ?? 'one of our listeners';
        $track = $ctx->track;
        $artist = $ctx->artist;

        return "{$base}\n\nThis next track is a special request! {$from} asked us to play \"{$track}\" by {$artist}. Give it a warm, enthusiastic shout-out — mention {$from} by name and build up the track. Max 2-3 sentences.";
    }

    private function buildListenerNotePrompt(string $base, DjContext $ctx): string
    {
        $from = $ctx->listenerName ? "from {$ctx->listenerName}" : 'from a listener';
        $note = $ctx->listenerNote ?? '';

        return "{$base}\n\nA message came in {$from}: \"{$note}\". Read it out on air and respond to it warmly and naturally in radio DJ style. Keep it brief — max 2-3 sentences.";
    }

    private function buildAlarmPrompt(string $base, DjContext $ctx): string
    {
        $key     = $ctx->alarmKey     ?? 'UNKNOWN';
        $summary = $ctx->alarmSummary ?? 'a critical issue';

        return "You are the Fallout game Emergency Broadcast System — a formal, authoritative, slightly ominous automated government announcer. "
            . "Write an emergency broadcast in the style of the Fallout EBS: dramatic, bureaucratic, apocalyptic undertone, very serious. "
            . "Start with something like \"Attention. Attention. This is an Emergency Broadcast.\" or similar. "
            . "Then announce the critical issue [{$key}]: \"{$summary}\" as if it were a civilisation-level threat requiring immediate action. "
            . "Max 3 sentences. No hashtags. Respond in English.";
    }

    private function appendSongAnnouncement(string $prompt, DjContext $ctx): string
    {
        if ($ctx->track === '' || $ctx->artist === '') {
            return $prompt;
        }

        return $prompt . "\n\nFinish by smoothly announcing that coming up next is \"{$ctx->track}\" by {$ctx->artist}. Keep the full response under 5 sentences total.";
    }

    private static array $djStyles = [
        'energetic and hype — talk fast, punch your words, make listeners feel the rush',
        'smooth and cool — laid-back delivery, like you own the room, no rush at all',
        'warm and conversational — friendly neighbour, chatty, personal, as if talking to one person',
        'witty and dry — a little deadpan humour, a quick observation, clever wordplay',
        'storyteller — paint a brief picture, pull listeners in with a tiny narrative hook',
        'upbeat and cheeky — playful, teasing, light banter energy',
        'calm and reassuring — grounded, soothing tone, a steady presence on air',
        'punchy and brief — one sharp sentence hits harder than three average ones',
    ];

    private static array $openingBans = [
        'Hey there', 'Hey everyone', 'Hello everyone', 'Welcome back', 'Good morning everyone',
        'What\'s up', 'Alright', 'So', 'Did you know', 'You\'re listening to',
    ];

    private function pickStyle(): string
    {
        return self::$djStyles[array_rand(self::$djStyles)];
    }

    private function buildPrompt(DjContext $ctx): string
    {
        $lang  = $this->language === 'nl' ? 'Dutch' : 'English';
        $style = $this->pickStyle();
        $bans  = implode(', ', self::$openingBans);

        $base = "You are a radio DJ for {$ctx->station}. Your delivery style RIGHT NOW is: {$style}. "
            . "Write a short radio voice-over (max 2-3 sentences). No hashtags, no meta-commentary, just the actual on-air text. "
            . "Address the listener directly. Do NOT start with any of these overused openers: {$bans}. "
            . "Respond in {$lang}.";

        if (!empty($ctx->recentTexts)) {
            $list  = implode("\n", array_map(fn($t) => '- ' . $t, $ctx->recentTexts));
            $base .= "\n\nThese clips were already played recently — do NOT repeat or closely paraphrase any of them:\n{$list}";
        }

        return match ($ctx->type) {
            'morning' => $this->appendSongAnnouncement(
                "{$base}\n\nIt is {$ctx->hour}:00. Write an energetic good morning introduction for the workday.",
                $ctx,
            ),

            'lunch' => $this->appendSongAnnouncement(
                "{$base}\n\nIt is lunchtime ({$ctx->hour}:00). Write a friendly lunch break announcement. Relaxed tone.",
                $ctx,
            ),

            'afternoon' => $this->appendSongAnnouncement(
                "{$base}\n\nIt is {$ctx->hour}:00, the afternoon session begins. Write a motivating introduction for the second part of the day.",
                $ctx,
            ),

            'friday_afternoon' => $this->appendSongAnnouncement(
                "{$base}\n\nIt is Friday afternoon at {$ctx->hour}:00! The weekend is almost here. Write an enthusiastic Friday afternoon announcement that kicks off the weekend.",
                $ctx,
            ),

            'end_of_day' => $this->appendSongAnnouncement(
                "{$base}\n\nIt is {$ctx->hour}:00, the workday is almost over. Write a closing that greets and encourages the listeners.",
                $ctx,
            ),

            'weather' => $this->appendSongAnnouncement($this->buildWeatherPrompt($base, $ctx), $ctx),

            'news' => $this->appendSongAnnouncement($this->buildNewsPrompt($base, $ctx), $ctx),

            'birthday'  => $this->buildBirthdayPrompt($base, $ctx),

            'song_fact' => $this->buildSongFactPrompt($base, $ctx),

            'alarm' => $this->buildAlarmPrompt($base, $ctx),

            'listener_note' => $this->buildListenerNotePrompt($base, $ctx),

            'song_request'  => $this->buildSongRequestPrompt($base, $ctx),

            default => "{$base}\n\nYou are introducing the next track about to be played:\nTrack: {$ctx->track}\nArtist: {$ctx->artist}\n\nMake a short, enthusiastic introduction. Do not mention a time.",
        };
    }
}
