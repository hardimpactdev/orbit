<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Workspaces\WorkspacePlacement;
use RuntimeException;

final readonly class AppProxyRouteTargetResolver
{
    public function __construct(
        private WorkspacePlacement $placement = new WorkspacePlacement,
        private InstanceProxyRouteOwnershipResolver $ownership = new InstanceProxyRouteOwnershipResolver,
    ) {}

    public function instanceForRoute(ProxyRoute $route): ?Instance
    {
        if ($route->owner_type !== 'app') {
            return null;
        }

        return $this->ownership->resolve($route);
    }

    public function appForRoute(ProxyRoute $route, ?Instance $instance = null): ?App
    {
        $instance ??= $this->instanceForRoute($route);

        if (! $instance instanceof Instance) {
            return null;
        }

        $instance->loadMissing('app');

        return $instance->app;
    }

    public function nodeForRoute(ProxyRoute $route, ?Instance $instance = null): ?Node
    {
        $instance ??= $this->instanceForRoute($route);

        return $instance instanceof Instance ? $this->placement->nodeForInstance($instance) : null;
    }

    public function selector(Instance $instance): string
    {
        $instance->loadMissing('app');
        $app = $instance->app;

        if (! $app instanceof App) {
            throw new RuntimeException("Proxy route instance_id={$instance->id} has no parent App.");
        }

        return "{$app->name}.{$instance->name}";
    }

    public function routeDomain(ProxyRoute $route, ?Instance $instance = null): string
    {
        $instance ??= $this->instanceForRoute($route);
        $app = $instance instanceof Instance ? $this->appForRoute($route, $instance) : null;

        if ($instance instanceof Instance && $app instanceof App) {
            $domain = $this->placement->instanceUrlHost($instance, $app);

            if ($domain !== '') {
                return $domain;
            }
        }

        return $route->domain;
    }
}
