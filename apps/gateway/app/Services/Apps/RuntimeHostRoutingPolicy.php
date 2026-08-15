<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Nodes\NodeRoleName;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class RuntimeHostRoutingPolicy
{
    public function __construct(
        private WorkspacePlacement $placement = new WorkspacePlacement,
        private LaravelViteDevServerEnvironment $vite = new LaravelViteDevServerEnvironment,
    ) {}

    /** @return array<string, string> */
    public function forApp(App $app, ?Instance $instance = null): array
    {
        $node = $this->placement->runtimeNode($app, $instance);

        if (! $node instanceof Node || ! $this->appliesTo($app, $node, $instance)) {
            return [];
        }

        $host = $instance instanceof Instance
            ? $this->placement->instanceUrlHost($instance, $app)
            : $this->vite->host($app);

        return [
            $host => 'host-gateway',
        ];
    }

    /** @return array<string, string> */
    public function forWorkspace(Workspace $workspace): array
    {
        $workspace->loadMissing(['app', 'app.instances', 'instance']);
        $app = $workspace->app;

        if (! $app instanceof App) {
            return [];
        }

        $node = $this->placement->nodeForWorkspace($workspace);

        if (! $node instanceof Node || ! $this->appliesTo($app, $node)) {
            return [];
        }

        return [
            $this->vite->host($app, $workspace) => 'host-gateway',
        ];
    }

    private function appliesTo(App $app, Node $node, ?Instance $instance = null): bool
    {
        if (
            $app->runtimeKind() !== AppRuntimeKind::Php
            || $this->placement->runtimeEnvironment($app, $instance) === 'production'
        ) {
            return false;
        }

        $node->loadMissing('roleAssignments');

        return $node->hasActiveRole(NodeRoleName::AppDevelopment->value);
    }
}
