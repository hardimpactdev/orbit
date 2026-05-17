<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\WorkspaceLifecyclePhase;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspaceStepListPayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class WorkspaceStepListController implements Loggable
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function __invoke(string $phase, Request $request, WorkspaceStepListPayload $payload): JsonResponse
    {
        $phaseEnum = WorkspaceLifecyclePhase::tryFrom($phase);

        if (! $phaseEnum instanceof WorkspaceLifecyclePhase) {
            return $this->validationFailed('phase', $phase, 'Unsupported workspace step phase.');
        }

        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $appSlug = $this->stringQuery($request, 'app');
        $path = $this->stringQuery($request, 'path');

        if ($appSlug === null && $path === null) {
            return $this->validationFailed('app', null, 'Could not resolve parent app.');
        }

        $app = $appSlug !== null
            ? $this->resolveAppBySlug($appSlug)
            : $this->resolveAppByPath((string) $path);

        if (! $app instanceof App) {
            return $this->appNotFound($appSlug ?? (string) $path);
        }

        if (! $this->canReadApp($caller, $app)) {
            return $this->authorizationFailed(
                "You are not authorized to read {$phaseEnum->value}-step policy for '{$app->name}'.",
                ['app' => $app->name],
            );
        }

        return response()->json([
            'success' => [
                'data' => [
                    'steps' => $payload->forApp($app, $phaseEnum),
                ],
            ],
        ]);
    }

    private function resolveAppBySlug(string $slug): ?App
    {
        return App::query()
            ->with('node')
            ->where('name', $slug)
            ->first();
    }

    private function resolveAppByPath(string $path): ?App
    {
        $normalizedPath = rtrim($path, '/');

        $app = App::query()
            ->with('node')
            ->get()
            ->first(function (App $app) use ($normalizedPath): bool {
                $appPath = rtrim($app->path, '/');

                return $normalizedPath === $appPath || str_starts_with($normalizedPath, "{$appPath}/");
            });

        if ($app instanceof App) {
            return $app;
        }

        return Workspace::query()
            ->with('app.node')
            ->get()
            ->first(function (Workspace $workspace) use ($normalizedPath): bool {
                $workspacePath = rtrim($workspace->path, '/');

                return $normalizedPath === $workspacePath || str_starts_with($normalizedPath, "{$workspacePath}/");
            })?->app;
    }

    private function canReadApp(Node $caller, App $app): bool
    {
        if ($caller->role === 'gateway') {
            return true;
        }

        return DB::table('node_access')
            ->where('node_access.consumer_node_id', $caller->id)
            ->whereIn('node_access.serving_node_id', $this->hostedAppNodeIds())
            ->where('node_access.serving_node_id', $app->node_id)
            ->exists();
    }

    /**
     * @return list<int>
     */
    private function hostedAppNodeIds(): array
    {
        return array_values(array_unique([
            ...$this->nodeRoleAssignments->activeNodeIdsForRole('app-development'),
            ...$this->nodeRoleAssignments->activeNodeIdsForRole('app-production'),
        ]));
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function validationFailed(string $field, ?string $value, string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => array_filter([
                    'field' => $field,
                    'value' => $value,
                    'reason' => $field === 'app' ? 'missing_required_input' : null,
                ], fn (mixed $item): bool => $item !== null),
            ],
        ], 400);
    }

    private function appNotFound(string $app): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'workspace.app_not_found',
                'message' => "App '{$app}' not found.",
                'meta' => [
                    'app' => $app,
                ],
            ],
        ], 404);
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
        return 'api:GET /workspaces/steps/{phase}';
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
