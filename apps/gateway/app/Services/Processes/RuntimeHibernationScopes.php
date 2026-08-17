<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspacePlacement;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class RuntimeHibernationScopes
{
    public function __construct(
        private WorkspacePlacement $placement,
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function resolve(string $type, int $id): ?RuntimeHibernationScope
    {
        return match ($type) {
            'app-instance' => $this->resolveInstance($id),
            'workspace' => $this->resolveWorkspace($id),
            default => null,
        };
    }

    public function forContext(ProcessOwnerContext $context): ?RuntimeHibernationScope
    {
        if ($context->workspace instanceof Workspace) {
            return $this->resolveWorkspace($context->workspace->id);
        }

        if ($context->instance instanceof Instance) {
            return $this->resolveInstance($context->instance->id);
        }

        return null;
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

        foreach (Instance::query()->with('app')->get() as $instance) {
            $scope = $this->resolveInstance($instance->id);

            if ($scope instanceof RuntimeHibernationScope && $this->isDevelopmentNode($scope->node)) {
                $scopes[$scope->node->id][] = $scope;
            }
        }

        foreach (Workspace::query()->with(['instance'])->get() as $workspace) {
            $scope = $this->resolveWorkspace($workspace->id);

            if ($scope instanceof RuntimeHibernationScope && $this->isDevelopmentNode($scope->node)) {
                $scopes[$scope->node->id][] = $scope;
            }
        }

        return $scopes;
    }

    private function resolveInstance(int $id): ?RuntimeHibernationScope
    {
        $instance = Instance::query()
            ->with('app')
            ->find($id);
        $app = $instance?->app;
        $node = $instance instanceof Instance
            ? $this->placement->nodeForInstance($instance)
            : null;

        if (! $instance instanceof Instance || ! $app instanceof App || ! $node instanceof Node) {
            return null;
        }

        return new RuntimeHibernationScope(
            type: 'app-instance',
            id: $instance->id,
            node: $node,
            context: ProcessOwnerContext::forInstance($node, $instance),
        );
    }

    private function resolveWorkspace(int $id): ?RuntimeHibernationScope
    {
        $workspace = Workspace::query()
            ->with(['instance'])
            ->find($id);
        $app = $workspace?->app;
        $instance = $workspace?->instance;
        $node = $workspace instanceof Workspace
            ? $this->placement->nodeForWorkspace($workspace)
            : null;

        if (
            ! $workspace instanceof Workspace
            || ! $app instanceof App
            || ! $instance instanceof Instance
            || ! $node instanceof Node
        ) {
            return null;
        }

        return new RuntimeHibernationScope(
            type: 'workspace',
            id: $workspace->id,
            node: $node,
            context: ProcessOwnerContext::forWorkspace($node, $workspace, $instance),
        );
    }
}
