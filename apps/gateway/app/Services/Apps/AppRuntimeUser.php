<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Contracts\AppRuntimeUserResolver;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class AppRuntimeUser implements AppRuntimeUserResolver
{
    public function __construct(
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    public function forApp(App $app, ?Instance $instance = null): string
    {
        if (! $this->isProduction($app, $instance)) {
            return $this->nodeUser($app, $instance);
        }

        return $this->productionUser($app, $instance);
    }

    public function containerUserForApp(App $app, ?Instance $instance = null): ?string
    {
        if (! $this->isProduction($app, $instance)) {
            return null;
        }

        return $this->productionUser($app, $instance);
    }

    private function isProduction(App $app, ?Instance $instance): bool
    {
        if ($this->placement->runtimeEnvironment($app, $instance) === 'production') {
            return true;
        }

        $node = $this->placement->runtimeNode($app, $instance);

        return $node instanceof Node && $node->hasActiveRole('app-prod');
    }

    private function productionUser(App $app, ?Instance $instance): string
    {
        return (
            $this->userFromHomePath($this->placement->runtimePath($app, $instance)) ?? $this->nodeUser($app, $instance)
        );
    }

    private function userFromHomePath(string $path): ?string
    {
        if (preg_match('#^/home/([^/]+)/#', $path, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function nodeUser(App $app, ?Instance $instance): string
    {
        return $this->placement->runtimeNode($app, $instance)?->user ?: 'orbit';
    }
}
