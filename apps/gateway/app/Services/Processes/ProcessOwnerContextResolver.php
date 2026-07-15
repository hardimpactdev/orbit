<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\Apps\AppSelection;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Orbit\Sdk\Laravel\GatewayApiException;

/** @mago-expect lint:too-many-methods */
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
            $selections = $this->visibleAppSelections($visibleNodeIds);

            if ($selections->count() === 1) {
                $selection = $selections->first();

                if ($selection instanceof AppSelection) {
                    return $this->contextForApp($selection->app, $selection);
                }
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
        $selection = $this->resolveRequiredAppInstance($appName);
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
        $selection = $appName !== null ? $this->resolveRequiredAppInstance($appName) : null;
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
        $appInstance = $workspace->appInstance;

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

        if (! $appInstance instanceof AppInstance) {
            throw new GatewayApiException(
                "Workspace '{$workspaceName}' is not attached to an app instance.",
                'validation_failed',
                [
                    'field' => 'app',
                    'reason' => 'app_instance_required',
                    'app' => $app->name,
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
            appInstance: $appInstance,
        );
    }

    private function contextForApp(App $app, ?AppSelection $selection = null): ProcessOwnerContext
    {
        $app->loadMissing('node');
        $selection ??= $this->requireAppInstance(new AppSelection(app: $app));
        $instance = $selection->instance;
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

    private function resolveRequiredAppInstance(string $appName): ?AppSelection
    {
        try {
            $selection = $this->appSelectorResolver->resolve($appName);

            return $selection instanceof AppSelection
                ? $this->appSelectorResolver->requireInstance($selection)
                : null;
        } catch (AppSelectionResolutionFailed $exception) {
            throw new GatewayApiException(
                $exception->getMessage(),
                $exception->errorCode,
                $exception->meta,
            );
        }
    }

    private function requireAppInstance(AppSelection $selection): AppSelection
    {
        try {
            return $this->appSelectorResolver->requireInstance($selection);
        } catch (AppSelectionResolutionFailed $exception) {
            throw new GatewayApiException(
                $exception->getMessage(),
                $exception->errorCode,
                $exception->meta,
            );
        }
    }

    /**
     * @param  list<int>|null  $visibleNodeIds
     * @return SupportCollection<int, AppSelection>
     */
    private function visibleAppSelections(?array $visibleNodeIds): SupportCollection
    {
        /** @var Collection<int, AppSelection> $selections */
        $selections = AppInstance::query()
            ->with('app')
            ->get()
            ->map(fn (AppInstance $instance): AppSelection => new AppSelection(
                app: $instance->app,
                instance: $instance,
                instanceSelector: $instance->name,
            ))
            ->filter(function (AppSelection $selection) use ($visibleNodeIds): bool {
                $node = $selection->instance instanceof AppInstance
                    ? $this->placement->nodeForInstance($selection->instance)
                    : null;

                return (
                    $node instanceof Node
                    && ($visibleNodeIds === null || in_array($node->id, $visibleNodeIds, true))
                );
            })
            ->values();

        return new SupportCollection($selections->all());
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
