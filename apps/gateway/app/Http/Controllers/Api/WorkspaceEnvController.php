<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Data\Apps\AppSelection;
use App\Enums\ActivityLogType;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\Workspace;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Workspaces\WorkspaceEnvApplier;
use App\Services\Workspaces\WorkspaceEnvApplyException;
use App\Services\Workspaces\WorkspaceEnvRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 */
final class WorkspaceEnvController implements Loggable
{
    private ?Workspace $activitySubject = null;

    private string $currentAction = 'list';

    public function __construct(
        private readonly WorkspaceEnvRenderer $env,
        private readonly WorkspaceEnvApplier $applier,
        private readonly AppSelectorResolver $appSelectorResolver,
    ) {}

    #[RequiresPermission('workspace:read', servingNode: ServingNode::WorkspaceOwning)]
    public function index(string $workspace, Request $request): JsonResponse
    {
        $this->currentAction = 'list';
        $resolved = $this->resolve($workspace, $request);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $this->activitySubject = $resolved;

        return $this->success([
            ...$this->targetPayload($resolved),
            'variables' => $this->env->variables($resolved),
        ]);
    }

    #[RequiresPermission('workspace:write', servingNode: ServingNode::WorkspaceOwning)]
    public function store(string $workspace, Request $request): JsonResponse
    {
        $this->currentAction = 'set';
        $resolved = $this->resolve($workspace, $request);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $this->activitySubject = $resolved;

        if ($request->boolean('secret')) {
            return $this->validationFailed('secret', 'Secret env writes are not supported in this slice.');
        }

        $key = $this->stringInput($request, 'key');
        $value = $this->stringInput($request, 'value');

        if ($key === null || preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
            return $this->validationFailed(
                'key',
                'Env key must start with a letter and use only uppercase letters, digits, or underscores.',
            );
        }

        if ($value === null) {
            return $this->validationFailed('value', 'Env value is required.');
        }

        $variable = $this->env->set($resolved, $key, $value);
        $payload = [
            ...$this->targetPayload($resolved, stored: true),
            'variable' => $this->env->variablePayload($variable),
        ];

        if ($request->boolean('apply')) {
            try {
                $applyResult = $this->applier->apply($resolved, $this->env->applicableValues($resolved));
                $payload['apply'] = $applyResult->toArray();
                $payload['applied'] = true;
                $payload['runtime_restarted'] = $applyResult->runtimeRestarted();
            } catch (Throwable $exception) {
                return $this->applyFailed($resolved, $key, $exception);
            }
        }

        return $this->success($payload);
    }

    #[RequiresPermission('workspace:read', servingNode: ServingNode::WorkspaceOwning)]
    public function render(string $workspace, Request $request): JsonResponse
    {
        $this->currentAction = 'render';
        $resolved = $this->resolve($workspace, $request);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $this->activitySubject = $resolved;

        return $this->success([
            ...$this->targetPayload($resolved),
            'variables' => $this->env->render($resolved),
        ]);
    }

    #[RequiresPermission('workspace:read', servingNode: ServingNode::WorkspaceOwning)]
    public function resolveByPath(Request $request): JsonResponse
    {
        $selection = $this->selection($request);

        if ($selection instanceof JsonResponse) {
            return $selection;
        }

        $path = $this->stringInput($request, 'path');

        if ($path === null || ! str_starts_with($path, '/')) {
            return $this->validationFailed('path', 'An absolute workspace path is required.');
        }

        $normalized = rtrim(string: $path, characters: '/');
        $matches = $this
            ->queryForSelection($selection)
            ->get()
            ->filter(static function (Workspace $workspace) use ($normalized): bool {
                $workspacePath = rtrim(string: $workspace->path, characters: '/');

                return $normalized === $workspacePath || str_starts_with($normalized, "{$workspacePath}/");
            })
            ->sortByDesc(
                static fn (Model $model): int => $model instanceof Workspace ? strlen($model->path) : 0,
            )
            ->values();
        $workspace = $matches->first();

        if (! $workspace instanceof Workspace) {
            return $this->notFound($path);
        }

        $this->activitySubject = $workspace;
        $workspace->loadMissing(['app', 'appInstance']);

        return $this->success([
            'project' => $workspace->app?->name,
            'instance' => $workspace->appInstance->name,
            'workspace' => $workspace->name,
        ]);
    }

    private function resolve(string $name, Request $request): Workspace|JsonResponse
    {
        $selection = $this->selection($request);

        if ($selection instanceof JsonResponse) {
            return $selection;
        }

        $matches = $this
            ->queryForSelection($selection)
            ->where('name', $name)
            ->get();

        if ($matches->isEmpty()) {
            return $this->notFound($name);
        }

        if ($matches->count() > 1) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => "Workspace '{$name}' is ambiguous. Supply --instance with a concrete instance.",
                    'meta' => [
                        'field' => 'workspace',
                        'reason' => 'ambiguous',
                        'workspace' => $name,
                    ],
                ],
            ], 400);
        }

        return $matches->firstOrFail();
    }

    private function selection(Request $request): AppSelection|JsonResponse|null
    {
        $selector = $this->stringInput($request, 'instance');

        if ($selector === null) {
            return null;
        }

        try {
            return $this->appSelectorResolver->requireInstance(
                $this->appSelectorResolver->resolveRequired($selector),
            );
        } catch (AppSelectionResolutionFailed $exception) {
            return response()->json([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                    'meta' => $exception->meta,
                ],
            ], 400);
        }
    }

    /**
     * @return Builder<Workspace>
     */
    private function queryForSelection(?AppSelection $selection): Builder
    {
        /** @var Builder<Workspace> $query */
        $query = Workspace::query()->with(['app', 'appInstance']);

        if ($selection instanceof AppSelection) {
            $query->where('app_id', $selection->app->id);

            if ($selection->instance !== null) {
                $query->where('app_instance_id', $selection->instance->id);
            }
        }

        return $query;
    }

    /**
     * @return array{
     *     scope: string,
     *     project: string|null,
     *     instance: string,
     *     workspace: string,
     *     path: string,
     *     stored: bool,
     *     applied: bool,
     *     runtime_restarted: bool
     * }
     */
    private function targetPayload(Workspace $workspace, bool $stored = false): array
    {
        $workspace->loadMissing(['app', 'appInstance']);

        return [
            'scope' => 'workspace',
            'project' => $workspace->app?->name,
            'instance' => $workspace->appInstance->name,
            'workspace' => $workspace->name,
            'path' => $this->applier->envPath($workspace),
            'stored' => $stored,
            'applied' => false,
            'runtime_restarted' => false,
        ];
    }

    private function stringInput(Request $request, string $key): ?string
    {
        if (! is_string($request->input($key))) {
            return null;
        }

        $value = trim((string) $request->input($key));

        return $value === '' ? null : $value;
    }

    private function notFound(string $workspace): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'workspace.not_found',
                'message' => "Workspace '{$workspace}' not found.",
                'meta' => ['workspace' => $workspace],
            ],
        ], 404);
    }

    private function validationFailed(string $field, string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => ['field' => $field],
            ],
        ], 422);
    }

    private function applyFailed(Workspace $workspace, string $key, Throwable $exception): JsonResponse
    {
        $envWritten = $exception instanceof WorkspaceEnvApplyException
            ? $exception->envWritten
            : false;
        $phase = $exception instanceof WorkspaceEnvApplyException
            ? $exception->phase
            : 'env_write';
        $reason = $exception->getMessage();

        $message = $envWritten
            ? "Saved '{$key}' and wrote the workspace env file for '{$workspace->name}', but cache clear or runtime restart failed."
            : "Saved '{$key}' for workspace '{$workspace->name}' in the Orbit registry, but the workspace env file was not written.";

        return response()->json([
            'error' => [
                'code' => 'workspace.env_apply_failed',
                'message' => $message,
                'meta' => [
                    'workspace' => $workspace->name,
                    'key' => $key,
                    'phase' => $phase,
                    'stored' => true,
                    'env_written' => $envWritten,
                    'applied' => false,
                    'runtime_restarted' => false,
                    'reason' => $reason,
                ],
            ],
        ], 500);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function success(array $data): JsonResponse
    {
        return response()->json([
            'success' => [
                'data' => $data,
                'meta' => (object) [],
            ],
        ]);
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    public function effect(): ActivityLogType
    {
        return $this->currentAction === 'set' ? ActivityLogType::Write : ActivityLogType::Read;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return match ($this->currentAction) {
            'set' => 'api:POST /workspaces/{workspace}/env',
            'render' => 'api:GET /workspaces/{workspace}/env/render',
            default => 'api:GET /workspaces/{workspace}/env',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [];
    }

    public function description(): ?string
    {
        return null;
    }
}
