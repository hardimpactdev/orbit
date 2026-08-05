<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

final readonly class ApplicationLogProxyRouteMatcher
{
    public function __construct(
        private ApplicationLogProxyRouteOwner $owner = new ApplicationLogProxyRouteOwner,
    ) {}

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

        return $this->owner->resolve($host, $matches[0]);
    }
}
