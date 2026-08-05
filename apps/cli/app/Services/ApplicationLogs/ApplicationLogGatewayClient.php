<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Pure helpers over gateway JSON envelopes used by application-log commands.
 * Network I/O stays in the command; this class only shapes inventory/route payloads.
 */
final readonly class ApplicationLogGatewayClient
{
    public function __construct(
        private ApplicationLogWorkspaceInventory $workspaces = new ApplicationLogWorkspaceInventory,
        private ApplicationLogProxyRouteMatcher $routes = new ApplicationLogProxyRouteMatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $workspacesData  success.data from GET /api/workspaces
     * @return array{ok: true, workspace: string, instance: string}|array{ok: false, reason: string, count: int}
     */
    public function resolveWorkspaceSlug(string $slug, array $workspacesData): array
    {
        return $this->workspaces->resolveExactSlug($slug, $workspacesData);
    }

    /**
     * @param  list<array<string, mixed>>  $routes
     * @return array{
     *     ok: true,
     *     type: 'instance',
     *     selector: string
     * }|array{
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
    public function matchProxyHost(string $host, array $routes): array
    {
        return $this->routes->match($host, $routes);
    }

    /**
     * @param  array<string, mixed>  $successData
     * @return list<array<string, mixed>>
     */
    public function routeList(array $successData): array
    {
        $raw = $successData['routes'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];

        foreach ($raw as $route) {
            if (! is_array($route)) {
                continue;
            }

            $entry = [];

            foreach ($route as $key => $value) {
                if (is_string($key)) {
                    $entry[$key] = $value;
                }
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }
}
