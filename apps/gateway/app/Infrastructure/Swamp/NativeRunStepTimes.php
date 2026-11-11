<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Operations\OperationStatus;

/**
 * Closes progress still marked running when an Operation ends before its terminal history is read.
 * The terminal reader replaces this provisional progress with the complete native step history.
 */
final readonly class NativeRunStepTimes
{
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
}
