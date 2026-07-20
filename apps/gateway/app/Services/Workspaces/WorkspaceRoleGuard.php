<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use InvalidArgumentException;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class WorkspaceRoleGuard
{
    /** @var list<string> */
    private const array WORKSPACE_ADJACENT_DOCTOR_FAMILIES = [
        'workspace',
        'process',
        'proxy',
        'database_connection',
    ];

    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private WorkspacePlacement $placement,
    ) {}

    public function ensureNodeSupportsWorkspaces(Project $project, ?Node $node): void
    {
        $this->ensureNodeIsWorkspaceEligible($node, $project->name);
    }

    public function ensureNodeIsWorkspaceEligible(?Node $node, ?string $project = null): void
    {
        if (! $node instanceof Node) {
            return;
        }

        if ($this->nodeSupportsWorkspaces($node)) {
            return;
        }

        $meta = [
            'node' => $node->name,
            'role' => $this->nodeRoleAssignments->nodeHasActiveRole($node, 'app-prod')
                ? 'app-prod'
                : 'app-dev-required',
        ];

        if ($project !== null) {
            $meta = ['project' => $project, ...$meta];
        }

        throw new WorkspaceUnsupportedForProduction($meta);
    }

    public function nodeSupportsWorkspaces(?Node $node): bool
    {
        if (! $node instanceof Node) {
            return false;
        }

        return (
            ! $this->nodeRoleAssignments->nodeHasActiveRole($node, 'app-prod')
            && $this->nodeRoleAssignments->nodeHasActiveRole($node, 'app-dev')
        );
    }

    public function ensureNodeMayOperateWorkspaces(Node $node): void
    {
        if (! $this->nodeRoleAssignments->nodeHasActiveRole($node, 'app-prod')) {
            return;
        }

        $this->ensureNodeIsWorkspaceEligible($node);
    }

    /**
     * @param  list<string>  $families
     */
    public function ensureDoctorRequestDoesNotCrossProductionBoundary(
        Node $caller,
        Node $target,
        array $families,
        bool $hasWorkspaceScope,
    ): void {
        if (! $this->nodeRoleAssignments->nodeHasActiveRole($caller, 'app-prod')) {
            return;
        }

        if ($hasWorkspaceScope) {
            $this->ensureNodeMayOperateWorkspaces($caller);
        }

        if (
            $this->nodeSupportsWorkspaces($target)
            && array_intersect(self::WORKSPACE_ADJACENT_DOCTOR_FAMILIES, $families) !== []
        ) {
            $this->ensureNodeMayOperateWorkspaces($caller);
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    public function ensureGrantSupportsWorkspacePermissions(
        Node $consumer,
        Node $serving,
        array $permissions,
    ): void {
        foreach ($permissions as $permission) {
            if ($permission === '*' || str_starts_with($permission, 'workspace:')) {
                $this->ensureNodeMayOperateWorkspaces($consumer);

                if ($this->nodeRoleAssignments->nodeHasActiveRole($serving, 'app-prod')) {
                    $this->ensureNodeIsWorkspaceEligible($serving);
                }

                return;
            }
        }
    }

    public function ensureWorkspaceSupported(Workspace $workspace): void
    {
        $workspace->loadMissing(['app.node', 'app.instances', 'appInstance']);
        $app = $workspace->app;
        $instance = $this->placement->instanceForWorkspace($workspace);
        $node = $this->placement->nodeForWorkspace($workspace);

        if (! $app instanceof Project || ! $instance instanceof AppInstance || ! $node instanceof Node) {
            throw new WorkspaceUnsupportedForProduction(array_filter(
                [
                    'workspace' => $workspace->name,
                    'project' => $app?->name,
                    'instance' => $instance?->name,
                    'role' => 'app-dev-required',
                    'reason' => 'serving_node_unresolved',
                ],
                static fn (mixed $value): bool => $value !== null,
            ));
        }

        $this->ensureNodeSupportsWorkspaces($app, $node);
    }

    public function ensureNodeOwnsNoWorkspaces(Node $node): void
    {
        $workspace = Workspace::query()
            ->with(['app.node', 'app.instances', 'appInstance'])
            ->get()
            ->first(
                fn (Workspace $workspace): bool => $this->placement->nodeForWorkspace($workspace)?->id === $node->id,
            );

        if (! $workspace instanceof Workspace) {
            return;
        }

        throw new InvalidArgumentException(
            "Node '{$node->name}' cannot receive app-prod while workspace '{$workspace->name}' is assigned to it.",
        );
    }

    public function allowsWorkspaceTarget(Workspace $workspace, ?Node $consumer = null): bool
    {
        if (
            $consumer instanceof Node
            && $this->nodeRoleAssignments->nodeHasActiveRole($consumer, 'app-prod')
        ) {
            return false;
        }

        $workspace->loadMissing(['app.node', 'app.instances', 'appInstance']);
        $servingNode = $this->placement->nodeForWorkspace($workspace);

        return $this->nodeSupportsWorkspaces($servingNode);
    }
}
