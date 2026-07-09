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
        $appInstance = is_array($config['app_instance'] ?? null) ? $config['app_instance'] : [];

        return [
            $this->stringOrNull($appInstance['selector'] ?? null),
            $this->stringOrNull($appInstance['name'] ?? null),
            $this->stringOrNull($appInstance['domain'] ?? null),
            ($target['type'] ?? null) === 'app_instance' ? $this->stringOrNull($target['value'] ?? null) : null,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
