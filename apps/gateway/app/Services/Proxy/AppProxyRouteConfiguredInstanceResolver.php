<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Models\AppInstance;
use App\Models\Project;
use App\Models\ProxyRoute;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class AppProxyRouteConfiguredInstanceResolver
{
    public function __construct(
        private AppProxyRouteConfiguredInstanceSelector $selectors = new AppProxyRouteConfiguredInstanceSelector,
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    public function resolve(Project $app, ProxyRoute $route): ?AppInstance
    {
        $selector = $this->selectors->forRoute($route);

        if ($selector === null) {
            return null;
        }

        return $this->instanceMatchingSelector($app, $selector);
    }

    private function instanceMatchingSelector(Project $app, string $selector): ?AppInstance
    {
        $app->loadMissing('instances');
        $fullSelector = str_contains($selector, '.') ? $selector : "{$app->name}.{$selector}";

        foreach ($app->instances as $instance) {
            if ($this->placement->instanceMatchesSelector($instance, $selector, $fullSelector, $app)) {
                return $instance;
            }
        }

        return null;
    }
}
