<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Models\App;
use App\Models\Workspace;

final readonly class WorkspaceUrlResolver
{
    public function __construct(
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    public function url(Workspace $workspace): string
    {
        $workspace->loadMissing(['app.node', 'app.instances', 'appInstance', 'proxyRoutes']);

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
        $route = $workspace
            ->proxyRoutes
            ->where('owner_type', 'workspace')
            ->where('kind', 'workspace')
            ->sortBy('id')
            ->first();

        if ($route === null || ! is_string($route->domain)) {
            return '';
        }

        return $route->domain;
    }
}
