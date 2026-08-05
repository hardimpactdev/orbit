<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

final readonly class ApplicationLogProxyRouteMatcher
{
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
    public function match(string $host, array $routes): array
    {
        $matches = [];

        foreach ($routes as $route) {
            if (! is_array($route) || mb_strtolower((string) ($route['domain'] ?? '')) !== $host) {
                continue;
            }

            $matches[] = $route;
        }

        if ($matches === []) {
            return [
                'ok' => false,
                'field' => 'target',
                'message' => "No registered proxy route matches host '{$host}'.",
                'meta' => ['host' => $host],
            ];
        }

        if (count($matches) > 1) {
            return [
                'ok' => false,
                'field' => 'target',
                'message' => "Host '{$host}' matches more than one proxy route.",
                'meta' => [
                    'host' => $host,
                    'reason' => 'ambiguous_proxy_route',
                    'count' => count($matches),
                ],
            ];
        }

        return $this->fromRoute($host, $matches[0]);
    }

    /**
     * @param  array<string, mixed>  $route
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
    private function fromRoute(string $host, array $route): array
    {
        $owner = is_array($route['owner'] ?? null) ? $route['owner'] : [];
        $target = is_array($route['target'] ?? null) ? $route['target'] : [];
        $ownerType = $owner['type'] ?? $target['type'] ?? null;
        $ownerName = $owner['name'] ?? $target['value'] ?? null;

        if ($ownerType === 'workspace' && is_string($ownerName) && $ownerName !== '') {
            // Parent app.instance is route-entity authority (ProxyRouteQuery FK enrichment).
            $instance = $route['instance'] ?? null;

            if (! is_string($instance) || trim($instance) === '' || ! str_contains($instance, '.')) {
                return [
                    'ok' => false,
                    'field' => 'instance',
                    'message' => 'The workspace proxy route did not include a parent instance selector.',
                    'meta' => ['workspace' => $ownerName, 'host' => $host],
                ];
            }

            return [
                'ok' => true,
                'type' => 'workspace',
                'workspace' => $ownerName,
                'instance' => trim($instance),
            ];
        }

        if (is_string($ownerName) && str_contains($ownerName, '.')) {
            return [
                'ok' => true,
                'type' => 'instance',
                'selector' => $ownerName,
            ];
        }

        if ($ownerType === 'instance' && is_string($ownerName) && $ownerName !== '') {
            return [
                'ok' => true,
                'type' => 'instance',
                'selector' => $ownerName,
            ];
        }

        return [
            'ok' => false,
            'field' => 'target',
            'message' => "Host '{$host}' is not an instance or workspace proxy route.",
            'meta' => ['host' => $host],
        ];
    }
}
