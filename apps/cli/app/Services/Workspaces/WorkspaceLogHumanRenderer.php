<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use Illuminate\Support\Carbon;

final class WorkspaceLogHumanRenderer
{
    /**
     * @param  array<string, mixed>  $run
     * @return list<string>
     */
    public function lines(array $run): array
    {
        $lines = [
            "Workspace Log Run #{$run['id']}  ({$run['app']}/{$run['workspace']} on {$run['node']})",
        ];

        $steps = is_array($run['steps'] ?? null) ? $run['steps'] : [];

        if ($steps === []) {
            $lines[] = 'No step output recorded.';
            $lines[] = $this->footer($run);

            return $lines;
        }

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            array_push($lines, ...$this->stepLines($step));
        }

        $lines[] = '';
        $lines[] = $this->footer($run);

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $step
     * @return list<string>
     */
    private function stepLines(array $step): array
    {
        $status = is_string($step['status'] ?? null) ? $step['status'] : 'skipped';
        $name = is_string($step['name'] ?? null) ? $step['name'] : 'Step';
        $command = is_string($step['command'] ?? null) ? $step['command'] : null;
        $label = $command !== null && $command !== $name ? "{$name} ({$command})" : $name;
        $lines = ["{$this->statusIcon($status)} {$label}"];

        if ($status === 'failure' && ($step['exit_code'] ?? null) !== null) {
            $lines[] = "  [EXIT CODE {$step['exit_code']}]";
        }

        if ($status === 'skipped') {
            return $lines;
        }

        $stdout = is_string($step['stdout'] ?? null) ? $step['stdout'] : '';
        $stderr = is_string($step['stderr'] ?? null) ? $step['stderr'] : '';

        if ($stdout !== '') {
            array_push($lines, ...$this->outputBlockLines('STDOUT', $stdout));
        }

        if ($stderr !== '') {
            array_push($lines, ...$this->outputBlockLines('STDERR', $stderr));
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function outputBlockLines(string $label, string $output): array
    {
        $lines = ['', "  {$label}:"];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $lines[] = "  > {$line}";
        }

        return $lines;
    }

    private function statusIcon(string $status): string
    {
        return match ($status) {
            'success' => '✔',
            'failure' => '✘',
            default => '·',
        };
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function footer(array $run): string
    {
        $startedAt = is_string($run['started_at'] ?? null)
            ? Carbon::parse($run['started_at'])->format('Y-m-d H:i:s')
            : 'unknown';
        $duration = is_int($run['duration_ms'] ?? null)
            ? $this->formatDuration($run['duration_ms'])
            : 'running';

        return "Run #{$run['id']} {$run['status']} (started {$startedAt}, duration {$duration})";
    }

    private function formatDuration(int $durationMs): string
    {
        $seconds = $durationMs / 1000;

        if ($seconds < 60) {
            return rtrim(rtrim(number_format($seconds, 1), '0'), '.').'s';
        }

        $minutes = (int) floor($seconds / 60);

        if ($minutes < 60) {
            return "{$minutes}m";
        }

        return intdiv($minutes, 60).'h';
    }
}
