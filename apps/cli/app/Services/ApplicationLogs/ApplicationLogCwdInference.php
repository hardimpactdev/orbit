<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Interprets gateway path-resolution payloads for interactive cwd inference.
 */
final readonly class ApplicationLogCwdInference
{
    /**
     * Prefer workspace when resolve-by-path yields one; otherwise instance marker.
     *
     * @param  array<string, mixed>|null  $workspaceData  success.data from resolve-by-path
     * @return array{type: 'workspace', workspace: string, instance: string}|array{type: 'instance', selector: string}|array{error: string, reason: string}
     */
    public function forAppLog(?array $workspaceData, ?string $instanceMarker): array
    {
        $workspace = $this->workspaceTarget($workspaceData);

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
        $workspace = $this->workspaceTarget($workspaceData);

        if ($workspace !== null) {
            return $workspace;
        }

        return [
            'error' => 'No unambiguous workspace target could be inferred from the current directory.',
            'reason' => 'cwd_target_missing',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $workspaceData
     * @return array{type: 'workspace', workspace: string, instance: string}|null
     */
    private function workspaceTarget(?array $workspaceData): ?array
    {
        if ($workspaceData === null) {
            return null;
        }

        $workspace = is_array($workspaceData['workspace'] ?? null)
            ? $workspaceData['workspace']
            : $workspaceData;

        $name = $workspace['name'] ?? null;
        $app = $workspace['app'] ?? null;
        $instance = $workspace['instance'] ?? null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        if (! is_string($app) || $app === '' || ! is_string($instance) || $instance === '') {
            return null;
        }

        // Parent instance may be bare instance name or already app.instance.
        $parent = str_contains($instance, '.')
            ? $instance
            : "{$app}.{$instance}";

        return [
            'type' => 'workspace',
            'workspace' => $name,
            'instance' => $parent,
        ];
    }
}
