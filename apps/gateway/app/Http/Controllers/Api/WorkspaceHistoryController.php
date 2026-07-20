<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Data\Apps\AppSelection;
use App\Enums\ActivityLogType;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspaceHistoryPayload;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

#[RequiresPermission('workspace:history', servingNode: ServingNode::WorkspaceOwning)]
final readonly class WorkspaceHistoryController implements Loggable
{
    private const int DEFAULT_LIMIT = 50;

    private const int MAX_LIMIT = 500;

    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private NodeAccessAuthorizer $authorizer,
        private AppSelectorResolver $appSelectorResolver,
        private WorkspacePlacement $placement,
    ) {}

    public function __invoke(string $name, Request $request, WorkspaceHistoryPayload $payload): JsonResponse
    {
        return $this->showHistory($name, null, $request, $payload);
    }

    public function fromPath(Request $request, WorkspaceHistoryPayload $payload): JsonResponse
    {
        $path = $this->stringQuery($request, 'path');

        if ($path === null) {
            return $this->validationFailed('path', null, 'Workspace path is required.');
        }

        return $this->showHistory(null, $path, $request, $payload);
    }

    private function showHistory(
        ?string $name,
        ?string $path,
        Request $request,
        WorkspaceHistoryPayload $payload,
    ): JsonResponse {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $filters = $this->parseFilters($request);

        if ($filters instanceof JsonResponse) {
            return $filters;
        }

        $app = $this->stringQuery($request, 'instance');
        $selection = null;
        $visibleNodeIds = $this->visibleAppNodeIds($caller);

        if ($app !== null) {
            try {
                $selection = $this->appSelectorResolver->resolveRequired($app);
            } catch (AppSelectionResolutionFailed) {
                return response()->json([
                    'error' => [
                        'code' => 'workspace.not_found',
                        'message' => $path !== null
                            ? "Workspace for path '{$path}' not found or not visible."
                            : "Workspace not found: {$name}",
                        'meta' => $path !== null
                            ? ['path' => $path]
                            : ['name' => $name],
                    ],
                ], 404);
            }
        }

        if (! $this->callerIsGateway($caller) && $visibleNodeIds === []) {
            return $this->authorizationFailed('This node is not authorized to read workspace history.', [
                'workspace' => $name,
                'instance' => $app,
                'reason' => 'missing_permission',
                'missing_permission' => 'workspace:history',
            ]);
        }

        $workspace = $path !== null
            ? $this->matchingWorkspacePath($caller, $visibleNodeIds, $path, $selection)
            : $this->matchingWorkspaceByName($caller, $visibleNodeIds, (string) $name, $selection);

        if ($workspace instanceof JsonResponse) {
            return $workspace;
        }

        if (! $workspace instanceof Workspace) {
            return response()->json([
                'error' => [
                    'code' => 'workspace.not_found',
                    'message' => $path !== null
                        ? "Workspace for path '{$path}' not found or not visible."
                        : "Workspace not found: {$name}",
                    'meta' => $path !== null
                        ? ['path' => $path]
                        : ['name' => $name],
                ],
            ], 404);
        }

        if (! $this->canReadHistory($caller, $workspace)) {
            return $this->workspaceHistoryForbidden($workspace);
        }

        $history = $payload->forWorkspace(
            workspace: $workspace,
            limit: $filters['limit'],
            since: $filters['since'],
            until: $filters['until'],
            limitCapped: $filters['limit_capped'],
        );

        return response()->json([
            'success' => [
                'data' => [
                    'runs' => $history['runs'],
                ],
                'meta' => [
                    'pagination' => $history['pagination'],
                ],
            ],
        ]);
    }

    /**
     * @return array{limit: int, since: Carbon|null, until: Carbon|null, limit_capped: bool}|JsonResponse
     */
    private function parseFilters(Request $request): array|JsonResponse
    {
        $limitValue = $this->stringQuery($request, 'limit');
        $limit = self::DEFAULT_LIMIT;
        $limitCapped = false;

        if ($limitValue !== null) {
            if (! ctype_digit($limitValue) || (int) $limitValue < 1) {
                return $this->validationFailed(
                    'limit',
                    $limitValue,
                    'Invalid value for --limit: must be a positive integer.',
                );
            }

            $limit = (int) $limitValue;

            if ($limit > self::MAX_LIMIT) {
                $limit = self::MAX_LIMIT;
                $limitCapped = true;
            }
        }

        $since = $this->dateFilter($request, 'since');

        if ($since instanceof JsonResponse) {
            return $since;
        }

        $until = $this->dateFilter($request, 'until');

        if ($until instanceof JsonResponse) {
            return $until;
        }

        return [
            'limit' => $limit,
            'since' => $since,
            'until' => $until,
            'limit_capped' => $limitCapped,
        ];
    }

    private function dateFilter(Request $request, string $field): Carbon|JsonResponse|null
    {
        $value = $this->stringQuery($request, $field);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return $this->validationFailed(
                $field,
                $value,
                "Invalid value for --{$field}: must be an ISO 8601 datetime.",
            );
        }
    }

    /**
     * @return list<int>
     */
    private function visibleAppNodeIds(Node $caller): array
    {
        $visibleNodeIds = $this->hostedAppNodeIds();

        if ($this->callerIsGateway($caller)) {
            return $visibleNodeIds;
        }

        return Node::query()
            ->whereIn('id', $visibleNodeIds)
            ->get()
            ->filter(fn (Node $node): bool => $this->authorizer->allows($caller, $node, 'workspace:history'))
            ->map(fn (Node $node): int => $node->id)
            ->values()
            ->all();
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
     */
    private function matchingWorkspaceByName(
        Node $caller,
        array $visibleNodeIds,
        string $name,
        ?AppSelection $selection,
    ): Workspace|JsonResponse|null {
        /** @var Collection<int, Workspace> $workspaces */
        $workspaces = Workspace::query()
            ->with(['app.node', 'app.instances', 'appInstance'])
            ->where('name', $name)
            ->when($selection instanceof AppSelection, fn (Builder $query): Builder => $query->where(
                'app_id',
                $selection?->app->id,
            ))
            ->get();

        /** @var Collection<int, Workspace> $matches */
        $matches = $workspaces
            ->filter(function (Workspace $workspace, int $key) use ($caller, $visibleNodeIds, $selection): bool {
                $node = $this->placement->nodeForWorkspace($workspace);

                if (! $node instanceof Node) {
                    return false;
                }

                if (! $this->callerIsGateway($caller) && ! in_array($node->id, $visibleNodeIds, true)) {
                    return false;
                }

                return ! $selection instanceof AppSelection
                || $this->appSelectorResolver->matchesWorkspace($workspace, $selection);
            })
            ->values();

        if (! $selection instanceof AppSelection && $matches->count() > 1) {
            return response()->json([
                'error' => [
                    'code' => 'workspace.ambiguous_name',
                    'message' => "Ambiguous workspace: {$name}. Specify --instance.",
                    'meta' => [
                        'name' => $name,
                        'instances' => array_values(array_filter(array_map(
                            fn (Workspace $workspace): ?string => $workspace->app instanceof \App\Models\Project
                                ? "{$workspace->app->name}.{$workspace->appInstance->name}"
                                : null,
                            $matches->all(),
                        ))),
                    ],
                ],
            ], 400);
        }

        $workspace = $matches->first();

        return $workspace instanceof Workspace ? $workspace : null;
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function matchingWorkspacePath(
        Node $caller,
        array $visibleNodeIds,
        string $path,
        ?AppSelection $selection,
    ): ?Workspace {
        $normalizedPath = rtrim($path, '/');

        return Workspace::query()
            ->with(['app.node', 'app.instances', 'appInstance'])
            ->when($selection instanceof AppSelection, fn (Builder $query): Builder => $query->where(
                'app_id',
                $selection?->app->id,
            ))
            ->get()
            ->first(function (Workspace $workspace) use ($caller, $visibleNodeIds, $normalizedPath, $selection): bool {
                $node = $this->placement->nodeForWorkspace($workspace);

                if (! $node instanceof Node) {
                    return false;
                }

                if (! $this->callerIsGateway($caller) && ! in_array($node->id, $visibleNodeIds, true)) {
                    return false;
                }

                if (
                    $selection instanceof AppSelection
                    && ! $this->appSelectorResolver->matchesWorkspace($workspace, $selection)
                ) {
                    return false;
                }

                $workspacePath = rtrim($workspace->path, '/');

                return $normalizedPath === $workspacePath || str_starts_with($normalizedPath, "{$workspacePath}/");
            });
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_scalar($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function callerIsGateway(Node $caller): bool
    {
        return $this->nodeRoleAssignments->nodeIsGateway($caller);
    }

    private function canReadHistory(Node $caller, Workspace $workspace): bool
    {
        $node = $this->placement->nodeForWorkspace($workspace);

        return $node instanceof Node && $this->authorizer->allows($caller, $node, 'workspace:history');
    }

    private function workspaceHistoryForbidden(Workspace $workspace): JsonResponse
    {
        $node = $this->placement->nodeForWorkspace($workspace);

        return $this->authorizationFailed(
            $node instanceof Node
                ? "This node is not authorized for 'workspace:history' on '{$node->name}'."
                : 'Workspace owning node could not be resolved.',
            [
                'reason' => 'missing_permission',
                'missing_permission' => 'workspace:history',
                ...($node instanceof Node ? ['serving_node' => $node->name] : []),
            ],
        );
    }

    private function validationFailed(string $field, ?string $value, string $message): JsonResponse
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
        return 'api:GET /workspaces/{name-or-path}/history';
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
