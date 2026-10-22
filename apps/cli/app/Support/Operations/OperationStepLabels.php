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
            'workspace_ready' => ['Ready workspace', 'Waiting for workspace', 'Workspace ready'],
            'push' => ['Push checked commit', 'Pushing checked commit', 'Pushed checked commit'],
            'pull_request' => ['Publish pull request', 'Publishing pull request', 'Published pull request'],
            'check' => ['Run task check', 'Running task check', 'Ran task check'],
            'commit' => ['Record checked tree', 'Recording checked tree', 'Recorded checked tree'],
            'turn_workspace' => ['Prepare workspace', 'Preparing workspace', 'Prepared workspace'],
            'thread' => ['Reserve thread', 'Reserving thread', 'Reserved thread'],
            'prepare_turn' => ['Prepare turn', 'Preparing turn', 'Prepared turn'],
            'send_turn' => ['Send turn', 'Sending turn', 'Sent turn'],
            'turn' => ['Wait for turn', 'Waiting for turn', 'Turn ended'],
            'turn_result' => ['Record turn', 'Recording turn', 'Recorded turn'],
            'provision' => ['Claim task VM', 'Claiming task VM', 'Claimed task VM'],
            'workspace' => ['Create workspace', 'Creating workspace', 'Created workspace'],
            'workspace_lease' => ['Acquire workspace', 'Acquiring workspace', 'Acquired workspace'],
            'route' => ['Finish workspace', 'Finishing workspace', 'Finished workspace'],
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
