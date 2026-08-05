<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Data\Apps\AppSelection;
use App\Enums\ActivityLogType;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceRoleGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** @mago-expect lint:kan-defect */
final readonly class WorkspaceListController implements Loggable
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private NodeAccessAuthorizer $authorizer,
        private AppSelectorResolver $appSelectorResolver,
        private WorkspacePlacement $placement,
        private WorkspaceRoleGuard $workspaceRoleGuard,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        try {
            $this->workspaceRoleGuard->ensureNodeMayOperateWorkspaces($caller);
        } catch (WorkspaceUnsupportedForProduction $exception) {
            return $this->workspaceUnsupportedForProduction($exception);
        }

        $app = $this->stringQuery($request, 'instance');
        $node = $this->stringQuery($request, 'node');
        $selection = null;

        if ($this->containsComma($app)) {
            return $this->validationFailed('instance', $app, "Unknown instance: '{$app}'.");
        }

        if ($this->containsComma($node)) {
            return $this->validationFailed('node', $node, "Unknown node: '{$node}'.");
        }

        $callerIsGateway = $this->nodeRoleAssignments->nodeIsGateway($caller);
        $visibleNodeIds = $this->visibleAppNodeIds($caller, $callerIsGateway);

        if (! $callerIsGateway && $visibleNodeIds === []) {
            return $this->authorizationFailed('This node is not authorized to read the workspace registry.', [
                'reason' => 'missing_permission',
                'missing_permission' => 'workspace:read',
            ]);
        }

        if ($app !== null) {
            try {
                $selection = $this->appSelectorResolver->resolveRequired($app);
            } catch (AppSelectionResolutionFailed) {
                return $this->validationFailed('instance', $app, "Unknown instance: '{$app}'.");
            }
        }

        if ($selection instanceof AppSelection && $selection->instance instanceof Instance) {
            try {
                $this->workspaceRoleGuard->ensureNodeSupportsWorkspaces(
                    $selection->app,
                    $this->placement->nodeForInstance($selection->instance),
                );
            } catch (WorkspaceUnsupportedForProduction $exception) {
                return $this->workspaceUnsupportedForProduction($exception);
            }
        }

        if (
            $selection instanceof AppSelection
            && ! $this->appFilterIsValid($selection, $callerIsGateway, $visibleNodeIds)
        ) {
            $app ??= $selection->app->name;

            return $this->validationFailed('instance', $app, "Unknown instance: '{$app}'.");
        }

        if ($node !== null && ! $this->nodeFilterIsValid($node, $callerIsGateway, $visibleNodeIds)) {
            return $this->validationFailed('node', $node, "Unknown node: '{$node}'.");
        }

        $workspaces = $this->fetchWorkspaces(
            callerIsGateway: $callerIsGateway,
            visibleNodeIds: $visibleNodeIds,
            selection: $selection,
            node: $node,
        );

        return response()->json([
            'success' => [
                'data' => [
                    'workspaces' => $this->workspacePayloads($workspaces),
                ],
            ],
        ]);
    }

    /**
     * @return list<int>
     */
    private function visibleAppNodeIds(Node $caller, bool $callerIsGateway): array
    {
        $visibleNodeIds = $this->hostedAppNodeIds();

        if ($callerIsGateway) {
            return $visibleNodeIds;
        }

        return Node::query()
            ->whereIn('id', $visibleNodeIds)
            ->get()
            ->filter(fn (Node $node): bool => $this->authorizer->allows($caller, $node, 'workspace:read'))
            ->map(fn (Node $node): int => $node->id)
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function appFilterIsValid(AppSelection $selection, bool $callerIsGateway, array $visibleNodeIds): bool
    {
        if ($callerIsGateway) {
            return true;
        }

        if ($selection->instance !== null) {
            $node = $this->placement->nodeForInstance($selection->instance);

            return $node instanceof Node && in_array($node->id, $visibleNodeIds, true);
        }

        $selection->app->loadMissing(['node', 'instances']);

        if ($selection->app->node instanceof Node && in_array($selection->app->node->id, $visibleNodeIds, true)) {
            return true;
        }

        return $selection
            ->app
            ->instances
            ->contains(function (Instance $instance) use ($visibleNodeIds): bool {
                $node = $this->placement->nodeForInstance($instance);

                return $node instanceof Node && in_array($node->id, $visibleNodeIds, true);
            });
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function nodeFilterIsValid(string $node, bool $callerIsGateway, array $visibleNodeIds): bool
    {
        $query = Node::query()
            ->where('name', $node)
            ->whereIn('id', $this->hostedAppNodeIds());

        if (! $callerIsGateway) {
            $query->whereIn('id', $visibleNodeIds);
        }

        return $query->exists();
    }

    /**
     * @return list<int>
     */
    private function hostedAppNodeIds(): array
    {
        return $this->nodeRoleAssignments->activeNodeIdsForRole('app-dev');
    }

    /**
     * @param  list<int>  $visibleNodeIds
     * @return Collection<int, Workspace>
     */
    private function fetchWorkspaces(
        bool $callerIsGateway,
        array $visibleNodeIds,
        ?AppSelection $selection,
        ?string $node,
    ): Collection {
        $query = Workspace::query()
            ->with(['app.node', 'app.instances', 'instance']);

        if ($selection instanceof AppSelection) {
            $query->where('app_id', $selection->app->id);
        }

        /** @var Collection<int, Workspace> $workspaces */
        $workspaces = $query->get();

        /** @var list<Workspace> $items */
        $items = [];

        foreach ($workspaces as $workspace) {
            $workspaceNode = $this->placement->nodeForWorkspace($workspace);

            if (! $workspaceNode instanceof Node) {
                continue;
            }

            if (! $this->workspaceRoleGuard->nodeSupportsWorkspaces($workspaceNode)) {
                continue;
            }

            if (! $callerIsGateway && ! in_array($workspaceNode->id, $visibleNodeIds, true)) {
                continue;
            }

            if (
                $selection instanceof AppSelection
                && ! $this->appSelectorResolver->matchesWorkspace($workspace, $selection)
            ) {
                continue;
            }

            if ($node !== null && $workspaceNode->name !== $node) {
                continue;
            }

            $items[] = $workspace;
        }

        usort(
            $items,
            fn (Workspace $first, Workspace $second): int => (
                [
                    mb_strtolower((string) $this->placement->nodeForWorkspace($first)?->name),
                    mb_strtolower((string) $first->app?->name),
                    mb_strtolower($first->name),
                ] <=> [
                    mb_strtolower((string) $this->placement->nodeForWorkspace($second)?->name),
                    mb_strtolower((string) $second->app?->name),
                    mb_strtolower($second->name),
                ]
            ),
        );

        /** @var Collection<int, Workspace> $workspaces */
        $workspaces = new Collection($items);

        return $workspaces;
    }

    private function workspaceUnsupportedForProduction(
        WorkspaceUnsupportedForProduction $exception,
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
                'meta' => $exception->meta,
            ],
        ], 422);
    }

    /**
     * @param  Collection<int, Workspace>  $workspaces
     * @return list<array<string, mixed>>
     */
    private function workspacePayloads(Collection $workspaces): array
    {
        return array_values(
            $workspaces
                ->map(fn (Workspace $workspace): array => [
                    'name' => $workspace->name,
                    'app' => $workspace->app?->name,
                    'instance' => $workspace->instance->name,
                    'node' => $this->placement->nodeForWorkspace($workspace)?->name,
                    'url' => $workspace->url(),
                    'lifecycle_status' => $workspace->lifecycle_status->value,
                ])
                ->all(),
        );
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function containsComma(?string $value): bool
    {
        return $value !== null && str_contains($value, ',');
    }

    private function validationFailed(string $field, string $value, string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => [
                    'field' => $field,
                    'value' => $value,
                ],
            ],
        ], 400);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function authorizationFailed(string $message, array $meta = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => $message,
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], 403);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:GET /workspaces';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return null;
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        return null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
