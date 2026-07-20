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
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceShowPayload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @mago-expect lint:kan-defect
 */
#[RequiresPermission('workspace:read', servingNode: ServingNode::WorkspaceOwning)]
final readonly class WorkspaceShowController implements Loggable
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private NodeAccessAuthorizer $authorizer,
        private AppSelectorResolver $appSelectorResolver,
        private WorkspacePlacement $placement,
    ) {}

    public function __invoke(string $name, Request $request, WorkspaceShowPayload $payload): JsonResponse
    {
        return $this->showWorkspace($name, $request, $payload);
    }

    public function fromPath(Request $request, WorkspaceShowPayload $payload): JsonResponse
    {
        $path = $this->stringQuery($request, 'path');

        return match ($path) {
            null => response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Workspace path is required.',
                    'meta' => [
                        'field' => 'path',
                    ],
                ],
            ], 400),
            default => $this->showWorkspaceForPath($path, $request, $payload),
        };
    }

    private function showWorkspace(string $name, Request $request, WorkspaceShowPayload $payload): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $app = $this->stringQuery($request, 'instance');
        $selection = null;
        $visibleNodeIds = $this->visibleAppNodeIds($caller);

        if ($app !== null) {
            try {
                $selection = $this->appSelectorResolver->resolveRequired($app);
            } catch (AppSelectionResolutionFailed $exception) {
                return $this->appSelectionFailed(
                    exception: $exception,
                    notFoundMessage: "Workspace '{$name}' not found or not visible.",
                    notFoundMeta: ['name' => $name],
                );
            }
        }

        if (! $this->callerIsGateway($caller) && $visibleNodeIds === []) {
            return $this->authorizationFailed("This caller is not authorized to inspect '{$name}'.", [
                'name' => $name,
                'instance' => $app,
                'reason' => 'missing_permission',
                'missing_permission' => 'workspace:read',
            ]);
        }

        $matches = $this->matchingWorkspaces($caller, $visibleNodeIds, $name, $selection);

        if ($matches->isEmpty()) {
            return response()->json([
                'error' => [
                    'code' => 'workspace.not_found',
                    'message' => "Workspace '{$name}' not found or not visible.",
                    'meta' => [
                        'name' => $name,
                    ],
                ],
            ], 404);
        }

        if ($selection instanceof AppSelection) {
            try {
                $selection = $this->appSelectorResolver->requireInstance($selection);
            } catch (AppSelectionResolutionFailed $exception) {
                return $this->appSelectionFailed(
                    exception: $exception,
                    notFoundMessage: "Workspace '{$name}' not found or not visible.",
                    notFoundMeta: ['name' => $name],
                );
            }

            $matches = $this->matchingWorkspaces($caller, $visibleNodeIds, $name, $selection);

            if ($matches->isEmpty()) {
                return response()->json([
                    'error' => [
                        'code' => 'workspace.not_found',
                        'message' => "Workspace '{$name}' not found or not visible.",
                        'meta' => [
                            'name' => $name,
                        ],
                    ],
                ], 404);
            }
        }

        if ($app === null && $matches->count() > 1) {
            return response()->json([
                'error' => [
                    'code' => 'workspace.ambiguous_name',
                    'message' => "Workspace name '{$name}' is ambiguous.",
                    'meta' => [
                        'name' => $name,
                        'instances' => $matches
                            ->map(fn (Workspace $workspace): ?string => $workspace->app instanceof \App\Models\Project
                                ? "{$workspace->app->name}.{$workspace->appInstance->name}"
                                : null)
                            ->filter()
                            ->values()
                            ->all(),
                    ],
                ],
            ], 400);
        }

        $workspace = $matches->firstOrFail();

        if (! $this->canReadWorkspace($caller, $workspace)) {
            return $this->workspaceReadForbidden($workspace);
        }

        return response()->json([
            'success' => [
                'data' => $payload->forWorkspace($workspace),
                'meta' => [
                    'registry_only' => true,
                ],
            ],
        ]);
    }

    private function showWorkspaceForPath(string $path, Request $request, WorkspaceShowPayload $payload): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $app = $this->stringQuery($request, 'instance');
        $selection = null;
        $visibleNodeIds = $this->visibleAppNodeIds($caller);

        if ($app !== null) {
            try {
                $selection = $this->appSelectorResolver->resolveRequired($app);
            } catch (AppSelectionResolutionFailed $exception) {
                return $this->appSelectionFailed(
                    exception: $exception,
                    notFoundMessage: "Workspace for path '{$path}' not found or not visible.",
                    notFoundMeta: ['path' => $path],
                );
            }
        }

        if (! $this->callerIsGateway($caller) && $visibleNodeIds === []) {
            return $this->authorizationFailed("This caller is not authorized to inspect '{$path}'.", [
                'path' => $path,
                'reason' => 'missing_permission',
                'missing_permission' => 'workspace:read',
            ]);
        }

        $workspace = $this->matchingWorkspacePath($caller, $visibleNodeIds, $path, $selection);

        if (! $workspace instanceof Workspace) {
            return response()->json([
                'error' => [
                    'code' => 'workspace.not_found',
                    'message' => "Workspace for path '{$path}' not found or not visible.",
                    'meta' => [
                        'path' => $path,
                    ],
                ],
            ], 404);
        }

        if ($selection instanceof AppSelection) {
            try {
                $selection = $this->appSelectorResolver->requireInstance($selection);
            } catch (AppSelectionResolutionFailed $exception) {
                return $this->appSelectionFailed(
                    exception: $exception,
                    notFoundMessage: "Workspace for path '{$path}' not found or not visible.",
                    notFoundMeta: ['path' => $path],
                );
            }

            $workspace = $this->matchingWorkspacePath($caller, $visibleNodeIds, $path, $selection);

            if (! $workspace instanceof Workspace) {
                return response()->json([
                    'error' => [
                        'code' => 'workspace.not_found',
                        'message' => "Workspace for path '{$path}' not found or not visible.",
                        'meta' => [
                            'path' => $path,
                        ],
                    ],
                ], 404);
            }
        }

        if (! $this->canReadWorkspace($caller, $workspace)) {
            return $this->workspaceReadForbidden($workspace);
        }

        return response()->json([
            'success' => [
                'data' => $payload->forWorkspace($workspace),
                'meta' => [
                    'registry_only' => true,
                ],
            ],
        ]);
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
            ->filter(fn (Node $node): bool => $this->authorizer->allows($caller, $node, 'workspace:read'))
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
     * @return Collection<int, Workspace>
     */
    private function matchingWorkspaces(
        Node $caller,
        array $visibleNodeIds,
        string $name,
        ?AppSelection $selection,
    ): Collection {
        /** @var Collection<int, Workspace> $workspaces */
        $workspaces = Workspace::query()
            ->with(['app.node', 'app.instances', 'appInstance', 'app.processes'])
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

                return match (true) {
                    ! $node instanceof Node => false,
                    ! $this->callerIsGateway($caller) && ! in_array($node->id, $visibleNodeIds, true) => false,
                    default => ! $selection instanceof AppSelection
                        || $this->appSelectorResolver->matchesWorkspace($workspace, $selection),
                };
            })
            ->values();

        return $matches;
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function matchingWorkspacePath(
        Node $caller,
        array $visibleNodeIds,
        string $path,
        ?AppSelection $selection = null,
    ): ?Workspace {
        $normalizedPath = rtrim($path, '/');

        return Workspace::query()
            ->with(['app.node', 'app.instances', 'appInstance', 'app.processes'])
            ->when($selection instanceof AppSelection, fn (Builder $query): Builder => $query->where(
                'app_id',
                $selection?->app->id,
            ))
            ->get()
            ->first(function (Workspace $workspace) use ($caller, $visibleNodeIds, $normalizedPath, $selection): bool {
                $node = $this->placement->nodeForWorkspace($workspace);

                $workspacePath = rtrim($workspace->path, '/');

                return match (true) {
                    ! $node instanceof Node => false,
                    ! $this->callerIsGateway($caller) && ! in_array($node->id, $visibleNodeIds, true) => false,
                    $selection instanceof AppSelection
                        && ! $this->appSelectorResolver->matchesWorkspace($workspace, $selection)
                        => false,
                    default => $normalizedPath === $workspacePath
                        || str_starts_with($normalizedPath, "{$workspacePath}/"),
                };
            });
    }

    private function callerIsGateway(Node $caller): bool
    {
        return $this->nodeRoleAssignments->nodeIsGateway($caller);
    }

    private function canReadWorkspace(Node $caller, Workspace $workspace): bool
    {
        $node = $this->placement->nodeForWorkspace($workspace);

        return $node instanceof Node && $this->authorizer->allows($caller, $node, 'workspace:read');
    }

    private function workspaceReadForbidden(Workspace $workspace): JsonResponse
    {
        $node = $this->placement->nodeForWorkspace($workspace);

        return $this->authorizationFailed(
            $node instanceof Node
                ? "This node is not authorized for 'workspace:read' on '{$node->name}'."
                : 'Workspace owning node could not be resolved.',
            [
                'reason' => 'missing_permission',
                'missing_permission' => 'workspace:read',
                ...($node instanceof Node ? ['serving_node' => $node->name] : []),
            ],
        );
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $notFoundMeta
     */
    private function appSelectionFailed(
        AppSelectionResolutionFailed $exception,
        string $notFoundMessage,
        array $notFoundMeta,
    ): JsonResponse {
        return match ($exception->meta['reason'] ?? null) {
            'instance_required' => $this->appInstanceRequired($exception),
            default => response()->json([
                'error' => [
                    'code' => 'workspace.not_found',
                    'message' => $notFoundMessage,
                    'meta' => $notFoundMeta,
                ],
            ], 404),
        };
    }

    private function appInstanceRequired(AppSelectionResolutionFailed $exception): JsonResponse
    {
        $meta = $exception->meta;
        unset($meta['instances']);

        return response()->json([
            'error' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'meta' => $meta,
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
        return 'api:GET /workspaces/{name-or-path}';
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
