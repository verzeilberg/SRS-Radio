<?php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class TextToSpeechService
{
    public function __construct(
        private string              $projectDir,
        private string              $serverBaseUrl,
        private string              $provider          = 'edge', // edge | piper | elevenlabs
        private string              $voice             = 'nl-NL-MaartenNeural',
        private string              $bedFile           = '',
        private float               $bedVol            = 0.20,
        private string              $piperModel        = '',
        private string              $elevenLabsApiKey  = '',
        private string              $elevenLabsVoiceId = '',
        private ?HttpClientInterface $httpClient        = null,
    ) {}

    public function setVoice(string $voice): void
    {
        $this->voice = $voice;
    }

    public function getServerBaseUrl(): string
    {
        return $this->serverBaseUrl;
    }

    public function setServerBaseUrl(string $url): void
    {
        $this->serverBaseUrl = $url;
    }

    public function generate(string $text): string
    {
        $dir = $this->projectDir . '/public/sounds/dj';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $voicePath = $dir . '/' . sha1($text . $this->provider . $this->voice . $this->piperModel . $this->elevenLabsVoiceId) . '_voice.mp3';
        $this->generateVoice($text, $voicePath);

        $bedAbsPath = $this->pickBedFile();
        if ($bedAbsPath && $this->ffmpegAvailable()) {
            $mixFilename = sha1($text . $this->provider . $this->voice . $this->piperModel . $this->elevenLabsVoiceId . $bedAbsPath . $this->bedVol) . '.mp3';
            $mixPath     = $dir . '/' . $mixFilename;

            if (!file_exists($mixPath) || filesize($mixPath) === 0) {
                @unlink($mixPath);
                $this->mixWithBed($voicePath, $bedAbsPath, $mixPath);
            }

            @unlink($voicePath);
            return rtrim($this->serverBaseUrl, '/') . '/sounds/dj/' . $mixFilename;
        }

        $finalFilename = sha1($text . $this->provider . $this->voice . $this->piperModel . $this->elevenLabsVoiceId) . '.mp3';
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

        return $this->getFileDuration($path);
    }

    public function getFileDuration(string $absolutePath): float
    {
        if (!file_exists($absolutePath)) {
            return 20.0;
        }

        $output  = shell_exec('ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($absolutePath) . ' 2>/dev/null');
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

        if (file_exists($path)) {
            return;
        }

        match ($this->provider) {
            'elevenlabs' => $this->generateVoiceElevenLabs($text, $path),
            'piper'      => $this->generateVoicePiper($text, $path),
            default      => $this->generateVoiceEdgeTts($text, $path),
        };
    }

    private function generateVoiceElevenLabs(string $text, string $path): void
    {
        if (!$this->elevenLabsApiKey || !$this->elevenLabsVoiceId) {
            throw new \RuntimeException('ElevenLabs API key or voice ID not configured.');
        }

        if ($this->httpClient === null) {
            throw new \RuntimeException('HttpClient not injected for ElevenLabs.');
        }

        $response = $this->httpClient->request(
            'POST',
            'https://api.elevenlabs.io/v1/text-to-speech/' . $this->elevenLabsVoiceId,
            [
                'headers' => [
                    'xi-api-key'   => $this->elevenLabsApiKey,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'audio/mpeg',
                ],
                'json' => [
                    'text'          => $text,
                    'model_id'      => 'eleven_multilingual_v2',
                    'voice_settings' => [
                        'stability'        => 0.5,
                        'similarity_boost' => 0.75,
                    ],
                ],
            ]
        );

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('ElevenLabs API error (' . $response->getStatusCode() . '): ' . $response->getContent(false));
        }

        file_put_contents($path, $response->getContent());

        if (!file_exists($path) || filesize($path) === 0) {
            @unlink($path);
            throw new \RuntimeException('ElevenLabs returned empty audio.');
        }
    }

    private function generateVoicePiper(string $text, string $mp3Path): void
    {
        $modelAbs = $this->projectDir . '/' . ltrim($this->piperModel, '/');
        $wavPath  = substr($mp3Path, 0, -4) . '.wav';

        $cmd    = 'echo ' . escapeshellarg($text)
                . ' | piper --model ' . escapeshellarg($modelAbs)
                . ' --output_file ' . escapeshellarg($wavPath) . ' 2>&1';
        $output = shell_exec($cmd);

        if (!file_exists($wavPath) || filesize($wavPath) === 0) {
            @unlink($wavPath);
            throw new \RuntimeException('piper mislukt: ' . ($output ?? 'geen output'));
        }

        // Convert WAV → MP3
        $cmd = 'ffmpeg -y -i ' . escapeshellarg($wavPath)
             . ' -q:a 4 ' . escapeshellarg($mp3Path) . ' 2>&1';
        shell_exec($cmd);
        @unlink($wavPath);

        if (!file_exists($mp3Path) || filesize($mp3Path) === 0) {
            @unlink($mp3Path);
            throw new \RuntimeException('piper WAV→MP3 conversie mislukt');
        }
    }

    private function generateVoiceEdgeTts(string $text, string $path): void
    {
        $cmd    = 'edge-tts --voice ' . escapeshellarg($this->voice)
                . ' --text ' . escapeshellarg($text)
                . ' --write-media ' . escapeshellarg($path) . ' 2>&1';
        $output = shell_exec($cmd);

        if (!file_exists($path) || filesize($path) === 0) {
            @unlink($path);
            throw new \RuntimeException('edge-tts mislukt: ' . ($output ?? 'geen output'));
        }
    }

    private function mixWithBed(string $voicePath, string $bedPath, string $outPath): void
    {
        $vol = number_format($this->bedVol, 2, '.', '');

        $voiceDur     = (float) trim(shell_exec(
            'ffprobe -v error -show_entries format=duration -of csv=p=0 '
            . escapeshellarg($voicePath) . ' 2>/dev/null'
        ) ?: '0');
        $tail         = 2.5;
        $bedFadeStart = number_format(max(0.0, $voiceDur + 0.3), 3, '.', '');
        $bedFadeDur   = number_format($tail - 0.3, 3, '.', '');

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

    private function pickBedFile(): string
    {
        if (!$this->bedFile) {
            return '';
        }

        $abs  = $this->projectDir . '/' . ltrim($this->bedFile, '/');
        $dir  = dirname($abs);
        $stem = rtrim(pathinfo($abs, PATHINFO_FILENAME), '0123456789');
        $ext  = pathinfo($abs, PATHINFO_EXTENSION);

        $candidates = array_filter(
            glob($dir . '/' . $stem . '*.' . $ext) ?: [],
            'file_exists'
        );

        return $candidates ? $candidates[array_rand($candidates)] : $abs;
    }

    private function ffmpegAvailable(): bool
    {
        return !empty(shell_exec('which ffmpeg 2>/dev/null'));
    }
}
