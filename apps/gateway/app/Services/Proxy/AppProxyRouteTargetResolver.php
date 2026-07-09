<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class AppProxyRouteTargetResolver
{
    public function __construct(
        private AppProxyRouteConfiguredInstanceResolver $configuredInstances = new AppProxyRouteConfiguredInstanceResolver,
        private AppProxyRouteDomainInstanceResolver $domainInstances = new AppProxyRouteDomainInstanceResolver,
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    public function appInstanceForRoute(ProxyRoute $route): ?AppInstance
    {
        if ($route->owner_type !== 'app' || $route->kind !== 'app') {
            return null;
        }

        $route->loadMissing(['app.instances', 'app.node']);
        $app = $route->app;

        if (! $app instanceof App) {
            return null;
        }

        return (
            $this->configuredInstances->resolve($app, $route) ?? $this->domainInstances->resolve($app, $route->domain)
        );
    }

    public function nodeForRoute(ProxyRoute $route, ?AppInstance $instance = null): ?Node
    {
        $instance ??= $this->appInstanceForRoute($route);

        if ($instance instanceof AppInstance) {
            $node = $this->placement->nodeForInstance($instance);

            if ($node instanceof Node) {
                return $node;
            }
        }

        $route->loadMissing('node');

        return $route->node;
    }

    public function selector(App $app, AppInstance $instance): string
    {
        return "{$app->name}.{$instance->name}";
    }

    public function routeDomain(ProxyRoute $route, App $app, ?AppInstance $instance = null): string
    {
        if ($instance instanceof AppInstance) {
            $domain = $this->placement->instanceUrlHost($instance, $app);

            if ($domain !== '') {
                return $domain;
            }
        }

        return $route->domain;
    }
}
