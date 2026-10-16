<?php

declare(strict_types=1);

namespace App\Support\Operations;

/** The waiting, running, and completed labels of an operation step in a progress tree. */
final class OperationStepLabels
{
    /** @return array{string, string, string} */
    public static function for(string $name): array
    {
        $labels = match ($name) {
            'source_preparation' => ['Prepare source', 'Preparing source', 'Prepared source'],
            'environment_sync' => ['Sync environment', 'Syncing environment', 'Synced environment'],
            'rollback' => ['Find retained release', 'Finding retained release', 'Found retained release'],
            'activation' => ['Activate release', 'Activating release', 'Activated release'],
            'php_refresh' => ['Refresh PHP cache', 'Refreshing PHP cache', 'Refreshed PHP cache'],
            'process_restart' => ['Restart Processes', 'Restarting Processes', 'Restarted Processes'],
            'cleanup' => ['Clean up releases', 'Cleaning up releases', 'Cleaned up releases'],
            default => null,
        };

        if ($labels !== null) {
            return $labels;
        }

        $dot = strpos($name, '.');
        $step = $dot === false ? $name : substr($name, $dot + 1);

        return ["Run {$step}", "Running {$step}", "Ran {$step}"];
    }
}
