<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\AppInstance;
use App\Models\Project;
use App\Services\Apps\AppInstanceEnvApplier;
use App\Services\Apps\AppInstanceEnvRenderer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class AppInstanceEnvController implements Loggable
{
    private ?Project $activitySubject = null;

    private string $currentAction = 'list';

    public function __construct(
        private readonly AppInstanceEnvRenderer $env,
        private readonly AppInstanceEnvApplier $applier,
    ) {}

    #[RequiresPermission('instance:read', servingNode: ServingNode::AppInstanceOwning)]
    public function index(string $project, string $instance): JsonResponse
    {
        $app = $project;
        $this->currentAction = 'list';
        $resolved = $this->resolve($app, $instance);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [$targetApp, $targetInstance] = $resolved;
        $this->activitySubject = $targetApp;

        return $this->success([
            ...$this->targetPayload($targetApp, $targetInstance),
            'variables' => $this->env->variables($targetInstance),
        ]);
    }

    #[RequiresPermission('instance:write', servingNode: ServingNode::AppInstanceOwning)]
    public function store(string $project, string $instance, Request $request): JsonResponse
    {
        $app = $project;
        $this->currentAction = 'set';
        $resolved = $this->resolve($app, $instance);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [$targetApp, $targetInstance] = $resolved;
        $this->activitySubject = $targetApp;

        if ($request->boolean('secret')) {
            return $this->validationFailed('secret', 'Secret env writes are not supported in this slice.');
        }

        $key = $this->stringInput($request, 'key');
        $value = $this->stringInput($request, 'value');

        if ($key === null || ! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            return $this->validationFailed(
                'key',
                'Env key must start with a letter and use only uppercase letters, digits, or underscores.',
            );
        }

        if ($value === null) {
            return $this->validationFailed('value', 'Env value is required.');
        }

        $variable = $this->env->set($targetInstance, $key, $value);

        $payload = [
            ...$this->targetPayload($targetApp, $targetInstance, stored: true),
            'variable' => $this->env->variablePayload($variable),
        ];

        if ($request->boolean('apply')) {
            try {
                $payload['apply'] = $this->applier
                    ->apply($targetApp, $targetInstance, $key, $value)
                    ->toArray();
                $payload['applied'] = true;
                $payload['runtime_restarted'] = in_array(
                    $payload['apply']['runtime_outcome'],
                    ['created', 'recreated', 'started'],
                    strict: true,
                );
            } catch (Throwable $exception) {
                return $this->applyFailed($targetApp, $targetInstance, $key, $exception);
            }
        }

        return $this->success($payload);
    }

    #[RequiresPermission('instance:read', servingNode: ServingNode::AppInstanceOwning)]
    public function render(string $project, string $instance): JsonResponse
    {
        $app = $project;
        $this->currentAction = 'render';
        $resolved = $this->resolve($app, $instance);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [$targetApp, $targetInstance] = $resolved;
        $this->activitySubject = $targetApp;

        return $this->success([
            ...$this->targetPayload($targetApp, $targetInstance),
            'variables' => $this->env->render($targetInstance),
        ]);
    }

    /**
     * @return array{
     *     scope: string,
     *     project: string,
     *     instance: string,
     *     workspace: null,
     *     path: string|null,
     *     stored: bool,
     *     applied: bool,
     *     runtime_restarted: bool
     * }
     */
    private function targetPayload(Project $app, AppInstance $instance, bool $stored = false): array
    {
        return [
            'scope' => 'instance',
            'project' => $app->name,
            'instance' => $instance->name,
            'workspace' => null,
            'path' => $this->applier->envPath($instance),
            'stored' => $stored,
            'applied' => false,
            'runtime_restarted' => false,
        ];
    }

    /**
     * @return array{0: Project, 1: AppInstance}|JsonResponse
     */
    private function resolve(string $app, string $instance): array|JsonResponse
    {
        $targetApp = Project::query()
            ->with('node')
            ->where('name', $app)
            ->orWhere('domain', $app)
            ->first();

        if (! $targetApp instanceof Project) {
            return response()->json([
                'error' => [
                    'code' => 'project.not_found',
                    'message' => "Project '{$app}' not found.",
                    'meta' => ['project' => $app],
                ],
            ], 404);
        }

        $targetInstance = $targetApp->instances()->where('name', $instance)->first();

        if (! $targetInstance instanceof AppInstance) {
            return response()->json([
                'error' => [
                    'code' => 'instance.not_found',
                    'message' => "Instance '{$instance}' was not found for project '{$targetApp->name}'.",
                    'meta' => [
                        'project' => $targetApp->name,
                        'instance' => $instance,
                    ],
                ],
            ], 404);
        }

        return [$targetApp, $targetInstance];
    }

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
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

    private function applyFailed(Project $app, AppInstance $instance, string $key, Throwable $exception): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'instance.env_apply_failed',
                'message' => "Saved '{$key}' for instance '{$instance->name}', but applying it to the instance runtime failed.",
                'meta' => [
                    'project' => $app->name,
                    'instance' => $instance->name,
                    'key' => $key,
                    'reason' => $exception->getMessage(),
                ],
            ],
        ], 500);
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
            'set' => 'api:POST /projects/{project}/instances/{instance}/env',
            'render' => 'api:GET /projects/{project}/instances/{instance}/env/render',
            default => 'api:GET /projects/{project}/instances/{instance}/env',
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
