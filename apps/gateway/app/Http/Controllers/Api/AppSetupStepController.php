<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Apps\AddAppSetupStep;
use App\Actions\Apps\RemoveAppSetupStep;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use App\Models\AppSetupStep;
use App\Models\Node;
use App\Services\Apps\AppSetupStepListPayload;
use App\Services\Nodes\Access\AuthorizationResult;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AppSetupStepController implements Loggable
{
    private ?AppSetupStep $activitySubject = null;

    public function __construct(
        private readonly NodeAccessAuthorizer $authorizer,
        private readonly AddAppSetupStep $addAppSetupStep,
        private readonly RemoveAppSetupStep $removeAppSetupStep,
    ) {}

    #[RequiresPermission('app:read', servingNode: ServingNode::AppOwning)]
    public function index(string $app, Request $request, AppSetupStepListPayload $payload): JsonResponse
    {
        $resolved = $this->resolveAuthorizedApp($app, $request, 'app:read');

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        return response()->json([
            'success' => [
                'data' => [
                    'steps' => $payload->forApp($resolved),
                ],
            ],
        ]);
    }

    #[RequiresPermission('app:write', servingNode: ServingNode::AppOwning)]
    public function store(string $app, Request $request, AppSetupStepListPayload $payload): JsonResponse
    {
        $resolved = $this->resolveAuthorizedApp($app, $request, 'app:write');

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $input = $request->json()->all();
        $input = is_array($input) ? $input : [];
        $command = $this->stringValue($input, 'command');

        if ($command === null) {
            return $this->validationFailed('command', 'Command is required.');
        }

        $timeout = $this->positiveIntValue($input, 'timeout', AppSetupStep::DEFAULT_TIMEOUT_SECONDS);

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
            return $this->error('app_setup.invalid_position', 'Both insertion flags cannot be supplied.', [
                'before' => $before,
                'after' => $after,
            ]);
        }

        $anchor = $this->anchorStep($resolved, $before ?? $after);

        if (($before !== null || $after !== null) && ! $anchor instanceof AppSetupStep) {
            return $this->stepNotFound((int) ($before ?? $after), $resolved->name);
        }

        $step = $this->addAppSetupStep->handle(
            appId: $resolved->id,
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

    #[RequiresPermission('app:write', servingNode: ServingNode::AppOwning)]
    public function destroy(string $app, int $step, Request $request, AppSetupStepListPayload $payload): JsonResponse
    {
        $resolved = $this->resolveAuthorizedApp($app, $request, 'app:write');

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        if ($request->boolean('destructive_consent') !== true) {
            return $this->validationFailed('force', 'Use --force to remove this app setup step.');
        }

        $model = AppSetupStep::query()
            ->where('app_id', $resolved->id)
            ->whereKey($step)
            ->first();

        if (! $model instanceof AppSetupStep) {
            return $this->stepNotFound($step, $resolved->name);
        }

        $removed = $payload->forStep($model);
        $this->activitySubject = $model;

        $this->removeAppSetupStep->handle($model);

        $remaining = AppSetupStep::query()
            ->where('app_id', $resolved->id)
            ->count();

        return response()->json([
            'success' => [
                'data' => [
                    'result' => ['action' => 'removed'],
                    'step' => $removed,
                ],
                'meta' => [
                    'remaining_step_count' => $remaining,
                    'new_step_count' => $remaining,
                ],
            ],
        ]);
    }

    private function resolveAuthorizedApp(string $selector, Request $request, string $permission): App|JsonResponse
    {
        $app = App::query()
            ->with('node')
            ->where('name', $selector)
            ->orWhere('domain', $selector)
            ->first();

        if (! $app instanceof App) {
            return $this->error('app.not_found', "App '{$selector}' was not found.", ['app' => $selector], 404);
        }

        if (! $app->node instanceof Node) {
            return $this->authorizationFailed("Could not resolve owning node for app '{$app->name}'.", [
                'app' => $app->name,
            ]);
        }

        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $authorization = $this->authorizer->authorize($caller, $app->node, $permission);

        if (! $authorization->allowed) {
            return $this->forbidden($app->node, $authorization, $permission);
        }

        return $app;
    }

    private function anchorStep(App $app, ?int $stepId): ?AppSetupStep
    {
        if ($stepId === null) {
            return null;
        }

        return AppSetupStep::query()
            ->where('app_id', $app->id)
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
        return $this->error('validation_failed', $message, array_merge(['field' => $field], $meta), 400);
    }

    private function stepNotFound(int $id, string $app): JsonResponse
    {
        return $this->error(
            'app_setup.step_not_found',
            "Setup step '{$id}' not found for app '{$app}'.",
            [
                'step_id' => $id,
                'app' => $app,
            ],
            404,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta = [], int $status = 422): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
            ],
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function authorizationFailed(string $message, array $meta = []): JsonResponse
    {
        return $this->error('authorization_failed', $message, empty($meta) ? [] : $meta, 403);
    }

    private function forbidden(Node $servingNode, AuthorizationResult $result, string $permission): JsonResponse
    {
        return $this->authorizationFailed(
            "This node is not authorized for '{$permission}' on '{$servingNode->name}'.",
            [
                'reason' => $result->reason,
                'missing_permission' => $result->missingPermission,
                'serving_node' => $servingNode->name,
            ],
        );
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:/apps/{app}/setup-steps';
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
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
