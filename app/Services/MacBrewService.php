<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class MacBrewService
{
    /**
     * Start PHP-FPM for a specific version.
     */
    public function startPhpFpm(string $version): bool
    {
        $result = Process::run("brew services start php@{$version}");

        return $result->successful();
    }

    /**
     * Stop PHP-FPM for a specific version.
     */
    public function stopPhpFpm(string $version): bool
    {
        $result = Process::run("brew services stop php@{$version}");

        return $result->successful();
    }

    /**
     * Restart PHP-FPM for a specific version.
     */
    public function restartPhpFpm(string $version): bool
    {
        $result = Process::run("brew services restart php@{$version}");

        return $result->successful();
    }

    /**
     * Start Caddy service.
     */
    public function startCaddy(): bool
    {
        $result = Process::run('brew services start caddy');

        return $result->successful();
    }

    /**
     * Stop Caddy service.
     */
    public function stopCaddy(): bool
    {
        $result = Process::run('brew services stop caddy');

        return $result->successful();
    }

    /**
     * Restart Caddy service.
     */
    public function restartCaddy(): bool
    {
        $result = Process::run('brew services restart caddy');

        return $result->successful();
    }

    /**
     * Reload Caddy configuration.
     */
    public function reloadCaddy(): bool
    {
        $result = Process::run('brew services reload caddy');

        return $result->successful();
    }

    /**
     * Check if a Homebrew service is running.
     */
    public function isServiceRunning(string $service): bool
    {
        $result = Process::run('brew services list');

        if (! $result->successful()) {
            return false;
        }

        $lines = explode("\n", $result->output());

        foreach ($lines as $line) {
            if (str_contains($line, $service)) {
                return str_contains($line, 'started');
            }
        }

        return false;
    }

    /**
     * Get the status of a Homebrew service.
     *
     * @return array{running: bool, status: string}
     */
    public function getServiceStatus(string $service): array
    {
        $result = Process::run('brew services list');

        if (! $result->successful()) {
            return ['running' => false, 'status' => 'unknown'];
        }

        $lines = explode("\n", $result->output());

        foreach ($lines as $line) {
            if (str_contains($line, $service)) {
                $running = str_contains($line, 'started');
                $status = $running ? 'running' : 'stopped';

                if (str_contains($line, 'error')) {
                    $status = 'error';
                }

                return ['running' => $running, 'status' => $status];
            }
        }

        return ['running' => false, 'status' => 'not found'];
    }

    /**
     * Get all PHP-FPM service statuses.
     *
     * @return array<string, array{running: bool, status: string}>
     */
    public function getAllPhpFpmStatuses(): array
    {
        $result = Process::run('brew services list');

        if (! $result->successful()) {
            return [];
        }

        $statuses = [];
        $lines = explode("\n", $result->output());

        foreach ($lines as $line) {
            if (preg_match('/^php@?(\d+\.\d+)?\s+(\w+)/', $line, $matches)) {
                $version = $matches[1] ?: 'default';
                $state = $matches[2];

                $statuses[$version] = [
                    'running' => $state === 'started',
                    'status' => $state === 'started' ? 'running' : ($state === 'error' ? 'error' : 'stopped'),
                ];
            }
        }

        return $statuses;
    }
}
