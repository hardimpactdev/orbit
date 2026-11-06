<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Operations\OperationStatus;

/**
 * Swamp's step events carry no start or end time, but the terminal run history has both for each step. This fills the
 * times of the progress steps an Operation recorded from the events, once, when the run ends. {@see self::settle()} closes any
 * step left `running` when the Operation ends.
 */
final readonly class NativeRunStepTimes
{
    /**
     * A step is matched by its job and name. When a job repeats a step name, such as a nested workflow's `result`, the
     * steps are matched by position, and only when the events and the history list the same number of them. A step that
     * cannot be matched with certainty keeps its null times.
     *
     * @param  array<mixed>  $steps  `native_progress.steps`, in event order
     * @param  list<array{job:string,name:string,started_at:string,finished_at:string|null}>  $recorded  the history steps in start order
     * @return array<mixed>
     */
    public function merge(array $steps, array $recorded): array
    {
        $history = [];
        foreach ($recorded as $step) {
            $history[$step['job']."\0".$step['name']][] = $step;
        }
        $events = [];
        foreach ($steps as $key => $row) {
            if (is_array($row) && is_string($row['job'] ?? null) && is_string($row['name'] ?? null)) {
                $events[$this->group($row['job'], $row['name'], $history)][] = $key;
            }
        }
        foreach ($events as $group => $keys) {
            $found = $history[$group] ?? [];
            if (count($found) !== count($keys)) {
                continue;
            }
            foreach ($keys as $position => $key) {
                $row = $steps[$key];
                if (is_array($row)) {
                    $steps[$key] = [...$row,
                        'started_at' => $this->time($row['started_at'] ?? null) ?? $found[$position]['started_at'],
                        'finished_at' => $this->time($row['finished_at'] ?? null) ?? $found[$position]['finished_at'], ];
                }
            }
        }

        return $steps;
    }

    /**
     * A repeated step of a nested workflow is named with its loop index after the step name. The history keeps the step
     * name alone, so a name that matches nothing is tried again without a trailing number.
     *
     * @param  array<string,list<array<string,mixed>>>  $history
     */
    private function group(string $job, string $name, array $history): string
    {
        $exact = $job."\0".$name;
        if (isset($history[$exact]) || preg_match('/\A(.+)-[0-9]+\z/D', $name, $match) !== 1) {
            return $exact;
        }

        return isset($history[$job."\0".$match[1]]) ? $job."\0".$match[1] : $exact;
    }

    /**
     * A finished Operation has no running step: a step that never reported its end takes the outcome of the Operation, or
     * `interrupted` when the Operation failed, since the step that failed is recorded on its own row.
     *
     * @param  array<mixed>|null  $state  the Operation state that holds `native_progress`
     * @return array<mixed>|null
     */
    public static function settle(?array $state, OperationStatus $status): ?array
    {
        $progress = $state['native_progress'] ?? null;
        $steps = is_array($progress) ? ($progress['steps'] ?? null) : null;
        if (! is_array($progress) || ! is_array($steps) || ! $status->terminal()) {
            return $state;
        }
        $ended = match ($status) {
            OperationStatus::Succeeded => 'succeeded',
            OperationStatus::Cancelled => 'cancelled',
            default => 'interrupted',
        };
        foreach ($steps as $key => $row) {
            if (is_array($row) && ($row['status'] ?? null) === 'running') {
                $steps[$key] = [...$row, 'status' => $ended];
            }
        }
        $state['native_progress'] = [...$progress, 'steps' => $steps, 'active' => []];

        return $state;
    }

    private function time(mixed $value): ?string
    {
        return is_string($value) && NativeRunTimestamp::parse($value) !== null ? $value : null;
    }
}
