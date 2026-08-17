<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Models\App;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Proxy\WorkspaceProxyRouteOwnershipResolver;

final readonly class WorkspaceUrlResolver
{
    public function __construct(
        private WorkspacePlacement $placement = new WorkspacePlacement,
        private WorkspaceProxyRouteOwnershipResolver $routeOwnership = new WorkspaceProxyRouteOwnershipResolver,
    ) {}

    public function url(Workspace $workspace): string
    {
        $workspace->loadMissing(['app.instances', 'instance', 'proxyRoutes']);

        $app = $workspace->app;

        if (! $app instanceof App) {
            return "https://{$workspace->name}";
        }

        $workspaceRouteHost = $this->workspaceRouteHost($workspace);

        if ($workspaceRouteHost !== '') {
            return "https://{$workspaceRouteHost}";
        }

        return "https://{$workspace->name}.{$this->placementUrlHost($workspace, $app)}";
    }

    private function placementUrlHost(Workspace $workspace, App $app): string
    {
        return $this->placement->baseUrlHost($workspace, $app);
    }

    private function workspaceRouteHost(Workspace $workspace): string
    {
        $routes = $workspace
            ->proxyRoutes
            ->where('owner_type', 'workspace')
            ->where('kind', 'workspace')
            ->sortBy('id');

        foreach ($routes as $route) {
            if (! $route instanceof ProxyRoute) {
                continue;
            }

            $ownership = $this->routeOwnership->resolve($route);

            if ($ownership !== null && $ownership->workspace->is($workspace)) {
                return $route->domain;
            }
        }

        return '';
    }
}
