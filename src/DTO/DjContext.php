<?php
namespace App\DTO;

class DjContext
{
    public function __construct(
        public string $station,
        public string $track,
        public string $artist,
        public string $mood,
        public int $hour,
        public ?string $previousTrack = null,
        public string $style = 'energy',
        public string $type = 'between_tracks', // between_tracks | morning | lunch | afternoon | end_of_day | weather | news | song_fact | birthday | alarm
        public ?array $weather = null,          // keys: city, description, temp, feels_like, humidity, wind_kmh
        public ?array $headlines = null,        // array of headline strings for 'news' type
        public ?string $birthdayColleague = null,
        public ?string $alarmKey = null,
        public ?string $alarmSummary = null,
        public ?string $listenerNote = null,
        public ?string $listenerName = null,
        public ?string $requesterName = null,
        public array $recentTexts = [],
    ) {}
}
