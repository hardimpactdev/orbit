<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Models\App;
use App\Models\Instance;
use App\Models\ProxyRoute;
use RuntimeException;

final readonly class InstanceProxyRouteOwnershipResolver
{
    private const array OwnerKinds = [
        'app' => 'app',
        'workspace' => 'workspace',
        'app-analytics' => 'proxy',
        'app-websocket' => 'proxy',
    ];

    private const array DirectOwnerTypes = [
        'app',
        'app-analytics',
        'app-websocket',
    ];

    private const array PublicBindingOwnerTypes = [
        'app-analytics',
        'app-websocket',
    ];

    public static function expectedKind(string $ownerType): ?string
    {
        return self::OwnerKinds[$ownerType] ?? null;
    }

    public static function isDirectOwner(string $ownerType): bool
    {
        return in_array($ownerType, self::DirectOwnerTypes, true);
    }

    public static function isPublicBindingOwner(string $ownerType): bool
    {
        return in_array($ownerType, self::PublicBindingOwnerTypes, true);
    }

    public function resolve(ProxyRoute $route): ?Instance
    {
        if (! self::isDirectOwner($route->owner_type)) {
            return null;
        }

        if ($route->kind !== self::expectedKind($route->owner_type) || $route->workspace_id !== null) {
            return null;
        }

        $route->loadMissing('instance.app');
        $instance = $route->instance;
        $app = $instance?->app;

        if (
            ! $instance instanceof Instance
            || ! $app instanceof App
            || $route->instance_id !== $instance->id
            || $route->app_id !== $instance->app_id
            || $route->app_id !== $app->id
        ) {
            return null;
        }

        return $instance;
    }

    public function resolveOrFail(ProxyRoute $route): Instance
    {
        return (
            $this->resolve($route) ?? throw new RuntimeException(
                "Proxy route '{$route->domain}' has invalid {$route->owner_type} ownership.",
            )
        );
    }
}
