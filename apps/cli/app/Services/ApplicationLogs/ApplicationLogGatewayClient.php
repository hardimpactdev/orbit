<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Pure helpers over gateway JSON envelopes used by application-log commands.
 * Network I/O stays in the command; this class only shapes/inventory-checks payloads.
 */
final readonly class ApplicationLogGatewayClient
{
    public function __construct(
        private ApplicationLogInstanceInventory $inventory = new ApplicationLogInstanceInventory,
        private ApplicationLogProxyRouteMatcher $routes = new ApplicationLogProxyRouteMatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $instancesData  success.data from GET /api/instances
     */
    public function isRegisteredInstanceSelector(string $token, array $instancesData): bool
    {
        return $this->inventory->contains($token, $this->inventory->selectors($instancesData));
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
        $routes = is_array($successData['routes'] ?? null) ? $successData['routes'] : [];
        $normalized = [];

        foreach ($routes as $route) {
            if (is_array($route)) {
                $normalized[] = $route;
            }
        }

        return $normalized;
    }
}
