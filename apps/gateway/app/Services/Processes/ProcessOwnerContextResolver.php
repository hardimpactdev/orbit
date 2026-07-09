<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\Apps\AppSelection;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Orbit\Sdk\Laravel\GatewayApiException;

final readonly class ProcessOwnerContextResolver
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private NodeAccessAuthorizer $authorizer,
        private AppSelectorResolver $appSelectorResolver,
        private WorkspacePlacement $placement,
    ) {}

    public function resolve(
        ?string $nodeName,
        ?string $appName,
        ?string $workspaceName,
    ): ProcessOwnerContext {
        return $this->resolveWithVisibility(
            nodeName: $nodeName,
            appName: $appName,
            workspaceName: $workspaceName,
            caller: null,
            permission: null,
            allowSingleVisibleAppDefault: false,
        );
    }

    public function resolveVisible(
        ?string $nodeName,
        ?string $appName,
        ?string $workspaceName,
        ?Node $caller,
        string $permission,
        bool $allowSingleVisibleAppDefault = false,
    ): ProcessOwnerContext {
        return $this->resolveWithVisibility(
            nodeName: $nodeName,
            appName: $appName,
            workspaceName: $workspaceName,
            caller: $caller,
            permission: $permission,
            allowSingleVisibleAppDefault: $allowSingleVisibleAppDefault,
        );
    }

    private function resolveWithVisibility(
        ?string $nodeName,
        ?string $appName,
        ?string $workspaceName,
        ?Node $caller,
        ?string $permission,
        bool $allowSingleVisibleAppDefault,
    ): ProcessOwnerContext {
        $visibleNodeIds = $permission === null
            ? null
            : $this->visibleNodeIds($caller, $permission);

        if (
            $permission !== null
            && $caller instanceof Node
            && ! $this->nodeRoleAssignments->nodeIsGateway($caller)
            && $visibleNodeIds === []
        ) {
            throw new GatewayApiException(
                'This node is not authorized to read process intent.',
                'authorization_failed',
                [
                    'reason' => 'missing_permission',
                    'missing_permission' => $permission,
                ],
            );
        }

        if ($nodeName !== null) {
            if ($appName !== null || $workspaceName !== null) {
                throw new GatewayApiException(
                    'A node context cannot be combined with app or workspace context.',
                    'validation_failed',
                    [
                        'field' => 'context',
                        'node' => $nodeName,
                        'app' => $appName,
                        'workspace' => $workspaceName,
                    ],
                );
            }

            return $this->resolveNode($nodeName, $visibleNodeIds);
        }

        if ($workspaceName !== null) {
            return $this->resolveWorkspace($workspaceName, $appName, $visibleNodeIds);
        }

        if ($appName !== null) {
            return $this->resolveApp($appName, $visibleNodeIds);
        }

        if ($allowSingleVisibleAppDefault) {
            $apps = $this->visibleApps($visibleNodeIds)->get();

            if ($apps->count() === 1) {
                $app = $apps->firstOrFail();
                $app->loadMissing('node');

                return $this->contextForApp($app);
            }
        }

        throw new GatewayApiException('A node, app, or workspace context is required.', 'validation_failed', [
            'field' => 'app',
        ]);
    }

    /**
     * @param  list<int>|null  $visibleNodeIds
     */
    private function resolveNode(string $nodeName, ?array $visibleNodeIds): ProcessOwnerContext
    {
        $query = Node::query()
            ->where('name', $nodeName);

        if ($visibleNodeIds !== null) {
            $query->whereIn('id', $visibleNodeIds);
        }

        $node = $query->first();

        if (! $node instanceof Node) {
            throw new GatewayApiException("Node '{$nodeName}' not found or not visible.", 'validation_failed', [
                'field' => 'node',
                'value' => $nodeName,
            ]);
        }

        return new ProcessOwnerContext(
            node: $node,
            app: null,
            workspace: null,
            owner: $node,
        );
    }

    /**
     * @param  list<int>|null  $visibleNodeIds
     */
    private function resolveApp(string $appName, ?array $visibleNodeIds): ProcessOwnerContext
    {
        $selection = $this->appSelectorResolver->resolve($appName);
        $app = $selection?->app;

        if (! $selection instanceof AppSelection || ! $app instanceof App) {
            throw new GatewayApiException("App '{$appName}' not found or not visible.", 'validation_failed', [
                'field' => 'app',
                'value' => $appName,
            ]);
        }

        $instance = $selection->instance;
        $node = $instance !== null
            ? $this->placement->nodeForInstance($instance)
            : $app->node;

        if ($visibleNodeIds !== null && (! $node instanceof Node || ! in_array($node->id, $visibleNodeIds, true))) {
            throw new GatewayApiException("App '{$appName}' not found or not visible.", 'validation_failed', [
                'field' => 'app',
                'value' => $appName,
            ]);
        }

        return $this->contextForApp($app, $selection);
    }

    /**
     * @param  list<int>|null  $visibleNodeIds
     */
    private function resolveWorkspace(
        string $workspaceName,
        ?string $appName,
        ?array $visibleNodeIds,
    ): ProcessOwnerContext {
        $selection = $appName !== null ? $this->appSelectorResolver->resolve($appName) : null;
        $query = Workspace::query()
            ->with(['app.node', 'app.instances', 'appInstance'])
            ->where('name', $workspaceName);

        if ($selection instanceof AppSelection) {
            $query->where('app_id', $selection->app->id);
        }

        /** @var Collection<int, Workspace> $workspaces */
        $workspaces = $query->get();

        /** @var Collection<int, Workspace> $matches */
        $matches = $workspaces
            ->filter(function (Workspace $workspace, int $key) use ($selection, $visibleNodeIds): bool {
                $node = $this->placement->nodeForWorkspace($workspace);

                if (! $node instanceof Node) {
                    return false;
                }

                if ($visibleNodeIds !== null && ! in_array($node->id, $visibleNodeIds, true)) {
                    return false;
                }

                return ! $selection instanceof AppSelection
                || $this->appSelectorResolver->matchesWorkspace($workspace, $selection);
            })
            ->values();

        if ($matches->isEmpty()) {
            throw new GatewayApiException(
                "Workspace '{$workspaceName}' not found or not visible.",
                'validation_failed',
                [
                    'field' => 'workspace',
                    'value' => $workspaceName,
                ],
            );
        }

        if ($appName === null && $matches->count() > 1) {
            throw new GatewayApiException("Workspace name '{$workspaceName}' is ambiguous.", 'validation_failed', [
                'field' => 'workspace',
                'value' => $workspaceName,
                'apps' => array_values(array_filter(array_map(
                    fn (Workspace $workspace): ?string => $workspace->app?->name,
                    $matches->all(),
                ))),
            ]);
        }

        $workspace = $matches->firstOrFail();
        $app = $workspace->app;

        if (! $app instanceof App) {
            throw new GatewayApiException(
                "Workspace '{$workspaceName}' is not attached to an app.",
                'validation_failed',
                [
                    'field' => 'workspace',
                    'value' => $workspaceName,
                ],
            );
        }

        $node = $this->placement->nodeForWorkspace($workspace);

        if (! $node instanceof Node) {
            throw new GatewayApiException("Workspace '{$workspaceName}' app has no node.", 'validation_failed', [
                'field' => 'workspace',
                'value' => $workspaceName,
            ]);
        }

        return new ProcessOwnerContext(
            node: $node,
            app: $app,
            workspace: $workspace,
            owner: $workspace,
        );
    }

    private function contextForApp(App $app, ?AppSelection $selection = null): ProcessOwnerContext
    {
        $app->loadMissing('node');
        $instance = $selection?->instance;
        $node = $instance !== null
            ? $this->placement->nodeForInstance($instance)
            : $app->node;

        if (! $node instanceof Node) {
            throw new GatewayApiException("App '{$app->name}' has no node.", 'validation_failed', [
                'field' => 'app',
                'value' => $app->name,
            ]);
        }

        return new ProcessOwnerContext(
            node: $node,
            app: $app,
            workspace: null,
            owner: $app,
            appInstance: $instance,
        );
    }

    /**
     * @param  list<int>|null  $visibleNodeIds
     * @return Builder<App>
     */
    private function visibleApps(?array $visibleNodeIds): Builder
    {
        /** @var Builder<App> $query */
        $query = App::query()
            ->with(['node', 'processes']);

        if ($visibleNodeIds !== null) {
            $query->whereIn('node_id', $visibleNodeIds);
        }

        return $query;
    }

    /**
     * @return list<int>|null
     */
    private function visibleNodeIds(?Node $caller, string $permission): ?array
    {
        if (! $caller instanceof Node || $this->nodeRoleAssignments->nodeIsGateway($caller)) {
            return null;
        }

        $candidateNodeIds = $this->nodeRoleAssignments->activeRoleBearingNodeIds();

        return Node::query()
            ->whereIn('id', $candidateNodeIds)
            ->get()
            ->filter(fn (Node $node): bool => $this->authorizer->allows($caller, $node, $permission))
            ->map(fn (Node $node): int => $node->id)
            ->values()
            ->all();
    }
}
