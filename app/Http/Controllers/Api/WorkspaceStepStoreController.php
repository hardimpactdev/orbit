<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\WorkspaceLifecyclePhase;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Models\WorkspaceStep;
use App\Services\Workspaces\WorkspaceStepListPayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WorkspaceStepStoreController implements Loggable
{
    private ?WorkspaceStep $activitySubject = null;

    public function __invoke(string $phase, Request $request, WorkspaceStepListPayload $payload): JsonResponse
    {
        $phaseEnum = WorkspaceLifecyclePhase::tryFrom($phase);

        if (! $phaseEnum instanceof WorkspaceLifecyclePhase) {
            return $this->validationFailed('phase', 'Unsupported workspace step phase.');
        }

        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        if ($caller->role === 'app') {
            return $this->callerRoleNotAllowed($caller->role);
        }

        $input = $this->input($request);
        $command = $this->stringValue($input, 'command');

        if ($command === null) {
            return $this->validationFailed('command', 'Command is required.');
        }

        $timeout = $this->positiveIntValue($input, 'timeout', WorkspaceStep::DEFAULT_TIMEOUT_SECONDS);

        if ($timeout === null) {
            return $this->validationFailed('timeout', 'Timeout must be a positive integer.', [
                'reason' => 'must_be_positive_integer',
            ]);
        }

        $before = $this->optionalPositiveIntValue($input, 'before');
        $after = $this->optionalPositiveIntValue($input, 'after');

        if ($before === false) {
            return $this->validationFailed('before', 'The --before option must be a positive integer.');
        }

        if ($after === false) {
            return $this->validationFailed('after', 'The --after option must be a positive integer.');
        }

        if (is_int($before) && is_int($after)) {
            return $this->invalidPosition($before, $after);
        }

        $appSlug = $this->stringValue($input, 'app');
        $path = $this->stringValue($input, 'path');

        if ($appSlug === null && $path === null) {
            return $this->validationFailed('app', 'Could not resolve parent app.', [
                'reason' => 'missing_required_input',
            ]);
        }

        $app = $appSlug !== null
            ? $this->resolveAppBySlug($appSlug)
            : $this->resolveAppByPath((string) $path);

        if (! $app instanceof App) {
            return $this->appNotFound($appSlug ?? (string) $path);
        }

        if (! $this->canManageApp($caller, $app)) {
            return $this->authorizationFailed("You are not authorized to manage workspace policy for '{$app->name}'.", [
                'app' => $app->name,
            ]);
        }

        $anchor = $this->anchorStep($app, $phaseEnum, $before ?? $after);

        if (($before !== null || $after !== null) && ! $anchor instanceof WorkspaceStep) {
            return $this->stepNotFound((int) ($before ?? $after), $app->name, $phaseEnum);
        }

        $step = WorkspaceStep::createOrdered(
            appId: $app->id,
            phase: $phaseEnum,
            command: $command,
            timeoutSeconds: $timeout,
            beforeStepId: is_int($before) ? $before : null,
            afterStepId: is_int($after) ? $after : null,
        );
        $this->activitySubject = $step;

        return response()->json([
            'success' => [
                'data' => [
                    'result' => ['action' => 'added'],
                    'step' => $payload->forStep($step),
                ],
                'meta' => (object) [],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        $input = $request->json()->all();

        return is_array($input) ? $input : [];
    }

    private function resolveAppBySlug(string $slug): ?App
    {
        return App::query()->where('name', $slug)->first();
    }

    private function resolveAppByPath(string $path): ?App
    {
        $normalizedPath = rtrim($path, '/');

        $app = App::query()
            ->get()
            ->first(function (App $app) use ($normalizedPath): bool {
                $appPath = rtrim($app->path, '/');

                return $normalizedPath === $appPath || str_starts_with($normalizedPath, "{$appPath}/");
            });

        if ($app instanceof App) {
            return $app;
        }

        return Workspace::query()
            ->with('app')
            ->get()
            ->first(function (Workspace $workspace) use ($normalizedPath): bool {
                $workspacePath = rtrim($workspace->path, '/');

                return $normalizedPath === $workspacePath || str_starts_with($normalizedPath, "{$workspacePath}/");
            })?->app;
    }

    private function canManageApp(Node $caller, App $app): bool
    {
        if ($caller->role === 'gateway') {
            return true;
        }

        return DB::table('node_access')
            ->join('nodes', 'nodes.id', '=', 'node_access.serving_node_id')
            ->where('node_access.consumer_node_id', $caller->id)
            ->where('nodes.role', 'app')
            ->where('nodes.status', 'active')
            ->where('nodes.id', $app->node_id)
            ->exists();
    }

    private function anchorStep(App $app, WorkspaceLifecyclePhase $phase, ?int $stepId): ?WorkspaceStep
    {
        if ($stepId === null) {
            return null;
        }

        return WorkspaceStep::query()
            ->where('app_id', $app->id)
            ->where('phase', $phase)
            ->whereKey($stepId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function stringValue(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function positiveIntValue(array $input, string $key, int $default): ?int
    {
        $value = $input[$key] ?? $default;

        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function optionalPositiveIntValue(array $input, string $key): int|false|null
    {
        $value = $input[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function validationFailed(string $field, string $message, array $meta = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => array_merge(['field' => $field], $meta),
            ],
        ], 400);
    }

    private function invalidPosition(int $before, int $after): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'workspace.invalid_position',
                'message' => 'Both insertion flags cannot be supplied.',
                'meta' => [
                    'before' => $before,
                    'after' => $after,
                ],
            ],
        ], 400);
    }

    private function stepNotFound(int $id, string $app, WorkspaceLifecyclePhase $phase): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'workspace.step_not_found',
                'message' => "Referenced insertion step '{$id}' not found for app '{$app}' in phase '{$phase->value}'.",
                'meta' => [
                    'id' => $id,
                    'app' => $app,
                    'phase' => $phase->value,
                ],
            ],
        ], 404);
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

    private function callerRoleNotAllowed(string $role): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'caller_role_not_allowed',
                'message' => 'App-node callers cannot manage workspace step policy.',
                'meta' => [
                    'caller_role' => $role,
                ],
            ],
        ], 403);
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
        return ActivityLogType::Write;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:POST /workspaces/steps/{phase}';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
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
