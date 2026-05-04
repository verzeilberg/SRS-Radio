<?php
namespace App\Service;

class TextToSpeechService
{
    public function __construct(
        private string $projectDir,
        private string $serverBaseUrl,
        private string $voice   = 'nl-NL-MaartenNeural',
        private string $bedFile = '',
        private float  $bedVol  = 0.20,
    ) {}

    public function setVoice(string $voice): void
    {
        $this->voice = $voice;
    }

    /**
     * Converts text to speech via edge-tts and optionally mixes in a background
     * music bed via ffmpeg. Returns an HTTP URL playable by Sonos.
     */
    public function generate(string $text): string
    {
        $dir = $this->projectDir . '/public/sounds/dj';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // 1. Generate voice-only MP3
        $voicePath = $dir . '/' . sha1($text . $this->voice) . '_voice.mp3';
        $this->generateVoice($text, $voicePath);

        // 2. Mix with bed if configured and ffmpeg is available
        $bedAbsPath = $this->bedFile ? $this->projectDir . '/' . ltrim($this->bedFile, '/') : '';
        if ($bedAbsPath && file_exists($bedAbsPath) && $this->ffmpegAvailable()) {
            $mixFilename = sha1($text . $this->voice . $this->bedFile . $this->bedVol) . '.mp3';
            $mixPath     = $dir . '/' . $mixFilename;

            if (!file_exists($mixPath) || filesize($mixPath) === 0) {
                @unlink($mixPath);
                $this->mixWithBed($voicePath, $bedAbsPath, $mixPath);
            }

            @unlink($voicePath);
            return rtrim($this->serverBaseUrl, '/') . '/sounds/dj/' . $mixFilename;
        }

        // No bed — normalise to the same -11 LUFS target as the bed-mix path
        $finalFilename = sha1($text . $this->voice) . '.mp3';
        $finalPath     = $dir . '/' . $finalFilename;
        if ($this->ffmpegAvailable()) {
            $cmd = 'ffmpeg -y -i ' . escapeshellarg($voicePath)
                 . ' -af loudnorm=I=-11:TP=-1.5 -q:a 4 '
                 . escapeshellarg($finalPath) . ' 2>&1';
            shell_exec($cmd);
            @unlink($voicePath);
        } elseif ($voicePath !== $finalPath) {
            rename($voicePath, $finalPath);
        }

        return rtrim($this->serverBaseUrl, '/') . '/sounds/dj/' . $finalFilename;
    }

    public function getDuration(string $url): float
    {
        $filename = basename(parse_url($url, PHP_URL_PATH));
        $path     = $this->projectDir . '/public/sounds/dj/' . $filename;

        if (!file_exists($path)) {
            return 20.0;
        }

        $output = shell_exec('ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($path) . ' 2>/dev/null');
        $seconds = (float) trim($output ?: '0');

        return $seconds > 0 ? $seconds : 20.0;
    }

    public function delete(string $url): void
    {
        $filename = basename(parse_url($url, PHP_URL_PATH));
        $path     = $this->projectDir . '/public/sounds/dj/' . $filename;
        if (file_exists($path)) {
            unlink($path);
        }
    }

    private function generateVoice(string $text, string $path): void
    {
        if (file_exists($path) && filesize($path) === 0) {
            unlink($path);
        }

        if (!file_exists($path)) {
            $cmd    = 'edge-tts --voice ' . escapeshellarg($this->voice)
                    . ' --text ' . escapeshellarg($text)
                    . ' --write-media ' . escapeshellarg($path) . ' 2>&1';
            $output = shell_exec($cmd);

            if (!file_exists($path) || filesize($path) === 0) {
                @unlink($path);
                throw new \RuntimeException('edge-tts mislukt: ' . ($output ?? 'geen output'));
            }
        }
    }

    private function mixWithBed(string $voicePath, string $bedPath, string $outPath): void
    {
        $vol = number_format($this->bedVol, 2, '.', '');

        // Determine voice duration so the bed fade-out can be timed precisely
        $voiceDur     = (float) trim(shell_exec(
            'ffprobe -v error -show_entries format=duration -of csv=p=0 '
            . escapeshellarg($voicePath) . ' 2>/dev/null'
        ) ?: '0');
        $tail         = 2.5;  // seconds of bed that plays after voice ends
        $bedFadeStart = number_format(max(0.0, $voiceDur + 0.3), 3, '.', '');
        $bedFadeDur   = number_format($tail - 0.3, 3, '.', '');

        // Pad voice with silence so amix keeps running during the bed tail,
        // then fade the bed out after the voice finishes.
        // loudnorm targets -11 LUFS (≈3 dB above Spotify's -14 LUFS) so the DJ
        // is always clearly audible regardless of TTS output level.
        $cmd = 'ffmpeg -y'
             . ' -i ' . escapeshellarg($voicePath)
             . ' -stream_loop -1 -i ' . escapeshellarg($bedPath)
             . ' -filter_complex '
             . escapeshellarg(
                 "[0:a]apad=pad_dur={$tail}[voice];"
                 . "[1:a]volume={$vol},afade=t=in:d=0.3,afade=t=out:st={$bedFadeStart}:d={$bedFadeDur}[bed];"
                 . '[voice][bed]amix=inputs=2:duration=first,loudnorm=I=-11:TP=-1.5[out]'
             )
             . ' -map [out] -q:a 4 '
             . escapeshellarg($outPath)
             . ' 2>&1';

        $output = shell_exec($cmd);

        if (!file_exists($outPath) || filesize($outPath) === 0) {
            @unlink($outPath);
            throw new \RuntimeException('ffmpeg mix mislukt: ' . ($output ?? 'geen output'));
        }
    }

    private function ffmpegAvailable(): bool
    {
        return !empty(shell_exec('which ffmpeg 2>/dev/null'));
    }
}
