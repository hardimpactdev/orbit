<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Row parsing for GET /api/workspaces inventory payloads.
 */
final readonly class ApplicationLogWorkspaceInventoryRows
{
    /**
     * @param  array<string, mixed>  $data
     * @return list<array{name: string, app: string, instance: string, selector: string}>
     */
    public function all(array $data): array
    {
        $workspaces = is_array($data['workspaces'] ?? null) ? $data['workspaces'] : [];
        $entries = [];

        foreach ($workspaces as $workspace) {
            if (! is_array($workspace)) {
                continue;
            }

            $name = $workspace['name'] ?? null;
            $app = $workspace['app'] ?? null;
            $instance = $workspace['instance'] ?? null;

            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            if (! is_string($app) || trim($app) === '' || ! is_string($instance) || trim($instance) === '') {
                continue;
            }

            $app = trim($app);
            $instance = trim($instance);
            $parent = str_contains($instance, '.') ? $instance : "{$app}.{$instance}";

            $entries[] = [
                'name' => trim($name),
                'app' => $app,
                'instance' => $instance,
                'selector' => $parent,
            ];
        }

        return $entries;
    }
}
