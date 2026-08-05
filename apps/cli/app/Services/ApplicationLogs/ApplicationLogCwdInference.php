<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Interprets gateway path-resolution payloads for interactive cwd inference.
 */
final readonly class ApplicationLogCwdInference
{
    public function __construct(
        private ApplicationLogWorkspacePathTarget $workspacePathTarget = new ApplicationLogWorkspacePathTarget,
    ) {}

    /**
     * Prefer workspace when resolve-by-path yields one; otherwise instance marker.
     *
     * @param  array<string, mixed>|null  $workspaceData  success.data from resolve-by-path
     * @return array{type: 'workspace', workspace: string, instance: string}|array{type: 'instance', selector: string}|array{error: string, reason: string}
     */
    public function forAppLog(?array $workspaceData, ?string $instanceMarker): array
    {
        $workspace = $this->workspacePathTarget->fromResolveByPathData($workspaceData);

        if ($workspace !== null) {
            return $workspace;
        }

        if (is_string($instanceMarker) && $instanceMarker !== '') {
            return [
                'type' => 'instance',
                'selector' => $instanceMarker,
            ];
        }

        return [
            'error' => 'No unambiguous application-log target could be inferred from the current directory.',
            'reason' => 'cwd_target_missing',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $workspaceData
     * @return array{type: 'workspace', workspace: string, instance: string}|array{error: string, reason: string}
     */
    public function forWorkspaceLog(?array $workspaceData): array
    {
        $workspace = $this->workspacePathTarget->fromResolveByPathData($workspaceData);

        if ($workspace !== null) {
            return $workspace;
        }

        return [
            'error' => 'No unambiguous workspace target could be inferred from the current directory.',
            'reason' => 'cwd_target_missing',
        ];
    }
}
