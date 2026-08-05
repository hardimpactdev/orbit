<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Resolves workspace-owned proxy routes into log targets.
 */
final readonly class ApplicationLogProxyWorkspaceOwner
{
    /**
     * @param  array<string, mixed>  $route
     * @return array{
     *     ok: true,
     *     type: 'workspace',
     *     workspace: string,
     *     instance: string
     * }|array{
     *     ok: false,
     *     field: string,
     *     message: string,
     *     meta: array<string, mixed>
     * }
     */
    public function resolve(string $host, string $workspace, array $route): array
    {
        // Parent app.instance is route-entity authority (ProxyRouteQuery FK enrichment).
        $instance = $route['instance'] ?? null;

        if (! is_string($instance) || trim($instance) === '' || ! str_contains($instance, '.')) {
            return [
                'ok' => false,
                'field' => 'instance',
                'message' => 'The workspace proxy route did not include a parent instance selector.',
                'meta' => ['workspace' => $workspace, 'host' => $host],
            ];
        }

        return [
            'ok' => true,
            'type' => 'workspace',
            'workspace' => $workspace,
            'instance' => trim($instance),
        ];
    }
}
