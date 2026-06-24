<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use App\Models\AppInstance;
use App\Services\Apps\AppInstanceEnvApplier;
use App\Services\Apps\AppInstanceEnvRenderer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class AppInstanceEnvController implements Loggable
{
    private ?App $activitySubject = null;

    private string $currentAction = 'list';

    public function __construct(
        private readonly AppInstanceEnvRenderer $env,
        private readonly AppInstanceEnvApplier $applier,
    ) {}

    #[RequiresPermission('app:read', servingNode: ServingNode::AppOwning)]
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
            'app' => $targetApp->name,
            'instance' => $targetInstance->name,
            'variables' => $this->env->variables($targetInstance),
        ]);
    }

    #[RequiresPermission('app:write', servingNode: ServingNode::AppOwning)]
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
            return $this->validationFailed('key', 'Env key must start with a letter and use only uppercase letters, digits, or underscores.');
        }

        if ($value === null) {
            return $this->validationFailed('value', 'Env value is required.');
        }

        $variable = $this->env->set($targetInstance, $key, $value);

        $payload = [
            'app' => $targetApp->name,
            'instance' => $targetInstance->name,
            'variable' => $this->env->variablePayload($variable),
        ];

        if ($request->boolean('apply')) {
            try {
                $payload['apply'] = $this->applier
                    ->apply($targetApp, $key, $value)
                    ->toArray();
            } catch (Throwable $exception) {
                return $this->applyFailed($targetApp, $targetInstance, $key, $exception);
            }
        }

        return $this->success($payload);
    }

    #[RequiresPermission('app:read', servingNode: ServingNode::AppOwning)]
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
            'app' => $targetApp->name,
            'instance' => $targetInstance->name,
            'variables' => $this->env->render($targetInstance),
        ]);
    }

    /**
     * @return array{0: App, 1: AppInstance}|JsonResponse
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

        if (! $targetInstance instanceof AppInstance) {
            return response()->json([
                'error' => [
                    'code' => 'app_instance.not_found',
                    'message' => "Instance '{$instance}' was not found for app '{$targetApp->name}'.",
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

    private function applyFailed(App $app, AppInstance $instance, string $key, Throwable $exception): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'app_instance.env_apply_failed',
                'message' => "Saved '{$key}' for instance '{$instance->name}', but applying it to the app runtime failed.",
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
