<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Models\ProxyRoute;

final readonly class AppProxyRouteConfiguredInstanceSelector
{
    public function forRoute(ProxyRoute $route): ?string
    {
        foreach ($this->configuredSelectors($route) as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @return list<string|null>
     */
    private function configuredSelectors(ProxyRoute $route): array
    {
        $config = is_array($route->config) ? $route->config : [];
        $target = is_array($config['target'] ?? null) ? $config['target'] : [];
        $instance = is_array($config['instance'] ?? null) ? $config['instance'] : [];

        return [
            $this->stringOrNull($instance['selector'] ?? null),
            $this->stringOrNull($instance['name'] ?? null),
            $this->stringOrNull($instance['domain'] ?? null),
            ($target['type'] ?? null) === 'instance' ? $this->stringOrNull($target['value'] ?? null) : null,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
