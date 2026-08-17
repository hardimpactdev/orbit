<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Models\ProxyRoute;

final readonly class ProxyRouteOwnershipCompatibility
{
    /**
     * @param  list<string>  $configKeys
     */
    public static function matches(ProxyRoute $actual, ProxyRoute $expected, array $configKeys = []): bool
    {
        if (
            $actual->domain !== $expected->domain
            || $actual->node_id !== $expected->node_id
            || $actual->app_id !== $expected->app_id
            || $actual->workspace_id !== $expected->workspace_id
            || $actual->instance_id !== $expected->instance_id
            || $actual->owner_type !== $expected->owner_type
            || $actual->kind !== $expected->kind
        ) {
            return false;
        }

        $actualConfig = is_array($actual->config) ? $actual->config : [];
        $expectedConfig = is_array($expected->config) ? $expected->config : [];

        return array_all(
            $configKeys,
            static fn (string $key): bool => (
                array_key_exists($key, $actualConfig)
                && array_key_exists($key, $expectedConfig)
                && $actualConfig[$key] === $expectedConfig[$key]
            ),
        );
    }
}
