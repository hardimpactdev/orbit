<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class RuntimeHibernationScopes
{
    public function __construct(
        private WorkspacePlacement $placement,
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function resolve(string $type, int $id): ?RuntimeHibernationScope
    {
        return match ($type) {
            'app-instance' => $this->resolveAppInstance($id),
            'workspace' => $this->resolveWorkspace($id),
            default => null,
        };
    }

    public function isDevelopmentNode(Node $node): bool
    {
        return $this->nodeRoleAssignments->nodeHasActiveRole($node, 'app-dev');
    }

    /**
     * @return array<int, list<RuntimeHibernationScope>>
     */
    public function byNode(): array
    {
        $scopes = [];

        foreach (AppInstance::query()->with('app.node')->get() as $instance) {
            $scope = $this->resolveAppInstance($instance->id);

            if ($scope instanceof RuntimeHibernationScope && $this->isDevelopmentNode($scope->node)) {
                $scopes[$scope->node->id][] = $scope;
            }
        }

        foreach (Workspace::query()->with(['app.node', 'appInstance'])->get() as $workspace) {
            $scope = $this->resolveWorkspace($workspace->id);

            if ($scope instanceof RuntimeHibernationScope && $this->isDevelopmentNode($scope->node)) {
                $scopes[$scope->node->id][] = $scope;
            }
        }

        return $scopes;
    }

    private function resolveAppInstance(int $id): ?RuntimeHibernationScope
    {
        $instance = AppInstance::query()
            ->with('app.node')
            ->find($id);
        $app = $instance?->app;
        $node = $instance instanceof AppInstance
            ? $this->placement->nodeForInstance($instance)
            : null;

        if (! $instance instanceof AppInstance || ! $app instanceof Project || ! $node instanceof Node) {
            return null;
        }

        return new RuntimeHibernationScope(
            type: 'app-instance',
            id: $instance->id,
            node: $node,
            context: new ProcessOwnerContext(
                node: $node,
                app: $app,
                workspace: null,
                owner: $app,
                appInstance: $instance,
            ),
        );
    }

    private function resolveWorkspace(int $id): ?RuntimeHibernationScope
    {
        $workspace = Workspace::query()
            ->with(['app.node', 'appInstance'])
            ->find($id);
        $app = $workspace?->app;
        $instance = $workspace?->appInstance;
        $node = $workspace instanceof Workspace
            ? $this->placement->nodeForWorkspace($workspace)
            : null;

        if (
            ! $workspace instanceof Workspace
            || ! $app instanceof Project
            || ! $instance instanceof AppInstance
            || ! $node instanceof Node
        ) {
            return null;
        }

        return new RuntimeHibernationScope(
            type: 'workspace',
            id: $workspace->id,
            node: $node,
            context: new ProcessOwnerContext(
                node: $node,
                app: $app,
                workspace: $workspace,
                owner: $workspace,
                appInstance: $instance,
            ),
        );
    }
}
