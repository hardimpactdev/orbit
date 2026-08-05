<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Maps gateway resolve-by-path workspace payloads to log targets.
 */
final readonly class ApplicationLogWorkspacePathTarget
{
    /**
     * @param  array<string, mixed>|null  $workspaceData  success.data from resolve-by-path
     * @return array{type: 'workspace', workspace: string, instance: string}|null
     */
    public function fromResolveByPathData(?array $workspaceData): ?array
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
