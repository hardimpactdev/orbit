<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class RuntimeHostRoutingPolicy
{
    public function __construct(
        private WorkspacePlacement $placement = new WorkspacePlacement,
        private LaravelViteDevServerEnvironment $vite = new LaravelViteDevServerEnvironment,
    ) {}

    /** @return array<string, string> */
    public function forApp(Project $app): array
    {
        if (! $this->appliesToApp($app)) {
            return [];
        }

        return [
            $this->vite->host($app) => 'host-gateway',
        ];
    }

    /** @return array<string, string> */
    public function forWorkspace(Workspace $workspace): array
    {
        $workspace->loadMissing(['app', 'app.instances', 'appInstance']);
        $app = $workspace->app;

        if (! $app instanceof Project) {
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

    private function appliesToApp(Project $app): bool
    {
        $app->loadMissing('node.roleAssignments');

        return $app->node instanceof Node && $this->appliesTo($app, $app->node);
    }

    private function appliesTo(Project $app, Node $node): bool
    {
        if ($app->runtimeKind() !== AppRuntimeKind::Php || $app->environment === 'production') {
            return false;
        }

        $node->loadMissing('roleAssignments');

        return $node->hasActiveRole(NodeRoleName::AppDevelopment->value);
    }
}
