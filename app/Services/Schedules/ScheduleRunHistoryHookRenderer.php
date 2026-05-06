<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Models\Schedule;

final readonly class ScheduleRunHistoryHookRenderer
{
    public function path(Schedule $schedule): string
    {
        return '/opt/orbit/schedules/hooks/'.hash('sha256', $schedule->schedule_key).'.sh';
    }

    public function hash(Schedule $schedule): string
    {
        return hash('sha256', $this->content($schedule));
    }

    public function installScript(Schedule $schedule): string
    {
        $path = $this->path($schedule);
        $content = $this->content($schedule);

        return sprintf(
            <<<'SH'
sudo install -d -m 0755 %s
printf %%s %s | base64 -d | sudo tee %s >/dev/null
sudo chmod 0755 %s
SH,
            escapeshellarg(dirname($path)),
            escapeshellarg(base64_encode($content)),
            escapeshellarg($path),
            escapeshellarg($path),
        );
    }

    private function content(Schedule $schedule): string
    {
        $schedule->loadMissing('app');

        $lines = [
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            '# Orbit schedule run-history hook',
            '# schedule_key='.$schedule->schedule_key,
        ];

        if ($schedule->scope === 'app' && is_string($schedule->app?->path) && $schedule->app->path !== '') {
            $lines[] = 'cd '.escapeshellarg($schedule->app->path);
        }

        $lines[] = 'exec /bin/bash -lc '.escapeshellarg($schedule->execution_value);

        return implode("\n", $lines)."\n";
    }
}
