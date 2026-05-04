<?php
namespace App\Service;

class RadioStateService
{
    private string $statePath;

    public function __construct(string $projectDir)
    {
        $this->statePath = $projectDir . '/var/radio-state.json';
    }

    public function setWaiting(string $startAt): void
    {
        file_put_contents($this->statePath, json_encode(['status' => 'waiting', 'start_at' => $startAt]));
    }

    public function setPlaying(): void
    {
        file_put_contents($this->statePath, json_encode(['status' => 'playing']));
    }

    public function setTrack(string $title, string $artist, int $durationMs, ?string $image = null): void
    {
        $state = $this->getState();
        $state['track_title']       = $title;
        $state['track_artist']      = $artist;
        $state['track_duration_ms'] = $durationMs;
        $state['track_started_at']  = time();
        $state['track_image']       = $image;
        file_put_contents($this->statePath, json_encode($state));
    }

    public function setNextTrack(string $title, string $artist): void
    {
        $state = $this->getState();
        $state['next_track_title']  = $title;
        $state['next_track_artist'] = $artist;
        file_put_contents($this->statePath, json_encode($state));
    }

    public function clearNextTrack(): void
    {
        $state = $this->getState();
        unset($state['next_track_title'], $state['next_track_artist']);
        file_put_contents($this->statePath, json_encode($state));
    }

    public function setPlaybackMethod(string $method): void
    {
        $state = $this->getState();
        $state['playback_method'] = $method;
        file_put_contents($this->statePath, json_encode($state));
    }

    public function setStopped(): void
    {
        file_put_contents($this->statePath, json_encode(['status' => 'idle', 'next_track_title' => null, 'next_track_artist' => null]));
    }

    public function setDjClip(string $url): void
    {
        $state = $this->getState();
        $state['dj_clip_url']  = $url;
        $state['dj_clip_done'] = false;
        file_put_contents($this->statePath, json_encode($state));
    }

    public function markDjClipDone(): void
    {
        $state = $this->getState();
        $state['dj_clip_done'] = true;
        file_put_contents($this->statePath, json_encode($state));
    }

    public function isDjClipDone(): bool
    {
        return (bool) ($this->getState()['dj_clip_done'] ?? false);
    }

    public function clearDjClip(): void
    {
        $state = $this->getState();
        unset($state['dj_clip_url'], $state['dj_clip_done']);
        file_put_contents($this->statePath, json_encode($state));
    }

    public function getState(): array
    {
        if (!file_exists($this->statePath)) {
            return ['status' => 'idle'];
        }

        return json_decode(file_get_contents($this->statePath), true) ?: ['status' => 'idle'];
    }
}
