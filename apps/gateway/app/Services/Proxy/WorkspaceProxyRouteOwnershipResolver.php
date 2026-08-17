<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Models\App;
use App\Models\Instance;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use RuntimeException;

final readonly class WorkspaceProxyRouteOwnershipResolver
{
    public function resolve(ProxyRoute $route): ?WorkspaceProxyRouteOwnership
    {
        $route->loadMissing(['workspace', 'instance.app']);
        $workspace = $route->workspace;
        $instance = $route->instance;
        $app = $instance?->app;

        if (
            $route->owner_type !== 'workspace'
            || $route->kind !== 'workspace'
            || ! $workspace instanceof Workspace
            || ! $instance instanceof Instance
            || ! $app instanceof App
            || $route->instance_id !== $workspace->instance_id
            || $route->app_id !== $instance->app_id
            || $workspace->app_id !== $instance->app_id
        ) {
            return null;
        }

        return new WorkspaceProxyRouteOwnership($workspace, $instance, $app);
    }

    public function resolveOrFail(ProxyRoute $route): WorkspaceProxyRouteOwnership
    {
        return (
            $this->resolve($route) ?? throw new RuntimeException(
                "Proxy route '{$route->domain}' has conflicting workspace ownership.",
            )
        );
    }
}
