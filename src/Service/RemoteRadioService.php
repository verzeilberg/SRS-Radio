<?php

namespace App\Service;

/**
 * Controls the radio on another machine over SSH.
 *
 * Configure the target host via REMOTE_RADIO_HOST / REMOTE_RADIO_USER /
 * REMOTE_RADIO_DIR / REMOTE_RADIO_NAME in .env.local.
 */
class RemoteRadioService
{
    public function __construct(
        private string $host,
        private string $user,
        private string $dir,
        private string $name,
    ) {}

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->user !== '';
    }

    public function getName(): string
    {
        return $this->name !== '' ? $this->name : $this->host;
    }

    public function status(): array
    {
        if (!$this->isConfigured()) {
            return ['configured' => false, 'reachable' => false, 'running' => false];
        }

        [$out, $code] = $this->ssh('pgrep -f "[b]in/console radio:start" | head -1');
        $reachable    = $code !== 255;
        $pid          = $reachable ? (int) trim($out) : 0;

        $state = [];
        if ($reachable) {
            [$json] = $this->ssh('cat ' . $this->dir . '/var/radio-state.json 2>/dev/null');
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $state = $decoded;
            }
        }

        return [
            'configured' => true,
            'name'       => $this->getName(),
            'reachable'  => $reachable,
            'running'    => $reachable && $pid > 0,
            'pid'        => $pid > 0 ? $pid : null,
            'state'      => $state,
        ];
    }

    public function start(): array
    {
        $cmd = sprintf('cd %s && nohup php bin/console radio:start > /dev/null 2>&1 < /dev/null & echo $!', $this->dir);
        [$out] = $this->ssh($cmd);

        $running = $this->status()['running'] ?? false;

        return [
            'success' => $running,
            'message' => $running
                ? 'Remote radio starting…'
                : 'Failed to start on ' . $this->getName() . ': ' . trim($out),
        ];
    }

    public function stop(): array
    {
        $cmd = sprintf('cd %s && php bin/console radio:stop', $this->dir);
        [$out] = $this->ssh($cmd);

        return [
            'success' => true,
            'message' => 'Stop signal sent to ' . $this->getName() . '.',
        ];
    }

    public function next(): array
    {
        $cmd = sprintf('cd %s && php bin/console radio:next', $this->dir);
        [$out] = $this->ssh($cmd);

        return [
            'success' => true,
            'message' => 'Skip requested on ' . $this->getName() . '.',
        ];
    }

    private function ssh(string $remoteCmd): array
    {
        $opts = '-o BatchMode=yes -o ConnectTimeout=5 -o ServerAliveInterval=10 -o StrictHostKeyChecking=accept-new';
        $cmd  = sprintf(
            'ssh %s %s@%s %s 2>&1',
            $opts,
            escapeshellarg($this->user),
            escapeshellarg($this->host),
            escapeshellarg($remoteCmd),
        );

        $output = [];
        $code   = 0;
        exec($cmd, $output, $code);

        return [implode("\n", $output), $code];
    }
}
