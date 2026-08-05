<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use App\Models\Instance;
use App\Services\Apps\InstanceEnvApplier;
use App\Services\Apps\InstanceEnvRenderer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class InstanceEnvController implements Loggable
{
    private ?App $activitySubject = null;

    private string $currentAction = 'list';

    public function __construct(
        private readonly InstanceEnvRenderer $env,
        private readonly InstanceEnvApplier $applier,
    ) {}

    #[RequiresPermission('instance:read', servingNode: ServingNode::InstanceOwning)]
    public function index(string $app, string $instance): JsonResponse
    {
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

    #[RequiresPermission('instance:write', servingNode: ServingNode::InstanceOwning)]
    public function store(string $app, string $instance, Request $request): JsonResponse
    {
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
                    ->apply($targetApp, $targetInstance, $this->env->applicableValues($targetInstance))
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

    #[RequiresPermission('instance:read', servingNode: ServingNode::InstanceOwning)]
    public function render(string $app, string $instance): JsonResponse
    {
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
     *     app: string,
     *     instance: string,
     *     workspace: null,
     *     path: string|null,
     *     stored: bool,
     *     applied: bool,
     *     runtime_restarted: bool
     * }
     */
    private function targetPayload(App $app, Instance $instance, bool $stored = false): array
    {
        return [
            'scope' => 'instance',
            'app' => $app->name,
            'instance' => $instance->name,
            'workspace' => null,
            'path' => $this->applier->envPath($instance),
            'stored' => $stored,
            'applied' => false,
            'runtime_restarted' => false,
        ];
    }

    /**
     * @return array{0: App, 1: Instance}|JsonResponse
     */
    private function resolve(string $app, string $instance): array|JsonResponse
    {
        $targetApp = App::query()
            ->with('node')
            ->where('name', $app)
            ->orWhere('domain', $app)
            ->first();

        if (! $targetApp instanceof App) {
            return response()->json([
                'error' => [
                    'code' => 'app.not_found',
                    'message' => "App '{$app}' not found.",
                    'meta' => ['app' => $app],
                ],
            ], 404);
        }

        $targetInstance = $targetApp->instances()->where('name', $instance)->first();

        if (! $targetInstance instanceof Instance) {
            return response()->json([
                'error' => [
                    'code' => 'instance.not_found',
                    'message' => "Instance '{$instance}' was not found for project '{$targetApp->name}'.",
                    'meta' => [
                        'app' => $targetApp->name,
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

    private function applyFailed(App $app, Instance $instance, string $key, Throwable $exception): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'instance.env_apply_failed',
                'message' => "Saved '{$key}' for instance '{$instance->name}', but applying it to the instance runtime failed.",
                'meta' => [
                    'app' => $app->name,
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
            'set' => 'api:POST /apps/{app}/instances/{instance}/env',
            'render' => 'api:GET /apps/{app}/instances/{instance}/env/render',
            default => 'api:GET /apps/{app}/instances/{instance}/env',
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
