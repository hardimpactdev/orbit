<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspaceShowPayload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class WorkspaceShowController implements Loggable
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function __invoke(string $name, Request $request, WorkspaceShowPayload $payload): JsonResponse
    {
        return $this->showWorkspace($name, $request, $payload);
    }

    public function fromPath(Request $request, WorkspaceShowPayload $payload): JsonResponse
    {
        $path = $this->stringQuery($request, 'path');

        if ($path === null) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Workspace path is required.',
                    'meta' => [
                        'field' => 'path',
                    ],
                ],
            ], 400);
        }

        return $this->showWorkspaceForPath($path, $request, $payload);
    }

    private function showWorkspace(string $name, Request $request, WorkspaceShowPayload $payload): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $app = $this->stringQuery($request, 'app');
        $visibleNodeIds = $this->visibleAppNodeIds($caller);

        if (! $this->callerIsGateway($caller) && $visibleNodeIds === []) {
            return $this->authorizationFailed("This caller is not authorized to inspect '{$name}'.", [
                'name' => $name,
                'app' => $app,
                'caller_role' => $this->nodeRoleAssignments->assignmentRoleLabel($caller),
            ]);
        }

        $matches = $this->matchingWorkspaces($caller, $visibleNodeIds, $name, $app);

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

        if ($app === null && $matches->count() > 1) {
            return response()->json([
                'error' => [
                    'code' => 'workspace.ambiguous_name',
                    'message' => "Workspace name '{$name}' is ambiguous.",
                    'meta' => [
                        'name' => $name,
                        'apps' => $matches->map(fn (Workspace $workspace): ?string => $workspace->app?->name)->filter()->values()->all(),
                    ],
                ],
            ], 400);
        }

        return response()->json([
            'success' => [
                'data' => $payload->forWorkspace($matches->firstOrFail()),
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

        $visibleNodeIds = $this->visibleAppNodeIds($caller);

        if (! $this->callerIsGateway($caller) && $visibleNodeIds === []) {
            return $this->authorizationFailed("This caller is not authorized to inspect '{$path}'.", [
                'path' => $path,
                'caller_role' => $this->nodeRoleAssignments->assignmentRoleLabel($caller),
            ]);
        }

        $workspace = $this->matchingWorkspacePath($caller, $visibleNodeIds, $path);

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

        return DB::table('node_access')
            ->where('node_access.consumer_node_id', $caller->id)
            ->whereIn('node_access.serving_node_id', $visibleNodeIds)
            ->pluck('node_access.serving_node_id')
            ->map(fn (mixed $nodeId): int => (int) $nodeId)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function hostedAppNodeIds(): array
    {
        return $this->nodeRoleAssignments->activeAppHostNodeIds();
    }

    /**
     * @param  list<int>  $visibleNodeIds
     * @return Collection<int, Workspace>
     */
    private function matchingWorkspaces(Node $caller, array $visibleNodeIds, string $name, ?string $app): Collection
    {
        return Workspace::query()
            ->with(['app.node', 'app.processes', 'proxyRoutes', 'runs'])
            ->where('name', $name)
            ->when(! $this->callerIsGateway($caller), fn (Builder $query): Builder => $query->whereHas('app', fn (Builder $query): Builder => $query->whereIn('node_id', $visibleNodeIds)))
            ->when($app !== null, fn (Builder $query): Builder => $query->whereHas('app', fn (Builder $query): Builder => $query->where('name', $app)))
            ->get();
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function matchingWorkspacePath(Node $caller, array $visibleNodeIds, string $path): ?Workspace
    {
        $normalizedPath = rtrim($path, '/');

        return Workspace::query()
            ->with(['app.node', 'app.processes', 'proxyRoutes', 'runs'])
            ->when(! $this->callerIsGateway($caller), fn (Builder $query): Builder => $query->whereHas('app', fn (Builder $query): Builder => $query->whereIn('node_id', $visibleNodeIds)))
            ->get()
            ->first(function (Workspace $workspace) use ($normalizedPath): bool {
                $workspacePath = rtrim($workspace->path, '/');

                return $normalizedPath === $workspacePath || str_starts_with($normalizedPath, "{$workspacePath}/");
            });
    }

    private function callerIsGateway(Node $caller): bool
    {
        return $this->nodeRoleAssignments->nodeIsGateway($caller);
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
