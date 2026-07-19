<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Apps\AddAppSetupStep;
use App\Actions\Apps\RemoveAppSetupStep;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\AppSetupStep;
use App\Models\Node;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Apps\AppSetupStepListPayload;
use App\Services\Nodes\Access\AuthorizationResult;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Workspaces\WorkspacePlacement;
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
        private readonly AppSelectorResolver $selectorResolver,
        private readonly WorkspacePlacement $placement,
    ) {}

    public function index(string $app, Request $request, AppSetupStepListPayload $payload): JsonResponse
    {
        $target = $this->resolveAuthorizedTarget($app, $request, 'app:read');

        if ($target instanceof JsonResponse) {
            return $target;
        }

        return response()->json([
            'success' => [
                'data' => [
                    'steps' => $payload->forAppInstance($target['instance']),
                ],
            ],
        ]);
    }

    public function store(string $app, Request $request, AppSetupStepListPayload $payload): JsonResponse
    {
        $target = $this->resolveAuthorizedTarget($app, $request, 'app:write');

        if ($target instanceof JsonResponse) {
            return $target;
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

        $instance = $target['instance'];
        $anchor = $this->anchorStep($instance, $before ?? $after);

        if (($before !== null || $after !== null) && ! $anchor instanceof AppSetupStep) {
            return $this->stepNotFound((int) ($before ?? $after), $target['app'], $instance);
        }

        $step = $this->addAppSetupStep->handle(
            appInstanceId: $instance->id,
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

    public function destroy(string $app, int $step, Request $request, AppSetupStepListPayload $payload): JsonResponse
    {
        $target = $this->resolveAuthorizedTarget($app, $request, 'app:write');

        if ($target instanceof JsonResponse) {
            return $target;
        }

        if ($request->boolean('destructive_consent') !== true) {
            return $this->validationFailed('force', 'Use --force to remove this app setup step.');
        }

        $instance = $target['instance'];
        $model = AppSetupStep::query()
            ->where('app_instance_id', $instance->id)
            ->whereKey($step)
            ->first();

        if (! $model instanceof AppSetupStep) {
            return $this->stepNotFound($step, $target['app'], $instance);
        }

        $removed = $payload->forStep($model);
        $this->activitySubject = $model;

        $this->removeAppSetupStep->handle($model);

        $remaining = AppSetupStep::query()
            ->where('app_instance_id', $instance->id)
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

    /**
     * @return array{app: App, instance: AppInstance, node: Node}|JsonResponse
     */
    private function resolveAuthorizedTarget(string $selector, Request $request, string $permission): array|JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $instanceIsVisible = fn (AppInstance $instance): bool => $this->selectorResolver->instanceIsVisibleTo(
            $caller,
            $instance,
            $permission,
        );

        try {
            $selection = $this->selectorResolver->resolve($selector, $instanceIsVisible);

            if ($selection === null) {
                return $this->error('app.not_found', "App '{$selector}' was not found.", ['app' => $selector], 404);
            }

            $selection = $this->selectorResolver->requireInstance(
                $selection,
                instanceIsVisible: $instanceIsVisible,
            );
        } catch (AppSelectionResolutionFailed $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->meta);
        }

        $instance = $selection->instance;

        if (! $instance instanceof AppInstance) {
            return $this->instanceUnavailable($selection->app, null);
        }

        $node = $this->placement->nodeForInstance($instance);

        if (! $node instanceof Node) {
            return $this->instanceUnavailable($selection->app, $instance);
        }

        $authorization = $this->authorizer->authorize($caller, $node, $permission);

        if (! $authorization->allowed) {
            return $this->forbidden($node, $instance, $authorization, $permission);
        }

        return [
            'app' => $selection->app,
            'instance' => $instance,
            'node' => $node,
        ];
    }

    private function anchorStep(AppInstance $instance, ?int $stepId): ?AppSetupStep
    {
        if ($stepId === null) {
            return null;
        }

        return AppSetupStep::query()
            ->where('app_instance_id', $instance->id)
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

    private function stepNotFound(int $id, App $app, AppInstance $instance): JsonResponse
    {
        return $this->error(
            'app_setup.step_not_found',
            "Setup step '{$id}' not found for app instance '{$app->name}.{$instance->name}'.",
            [
                'step_id' => $id,
                'app' => $app->name,
                'app_instance' => $instance->name,
            ],
            404,
        );
    }

    private function instanceUnavailable(App $app, ?AppInstance $instance): JsonResponse
    {
        return $this->error(
            'validation_failed',
            "App instance '{$app->name}.{$instance?->name}' does not resolve an Orbit serving node.",
            [
                'field' => 'app',
                'reason' => 'app_instance_unavailable',
                'app' => $app->name,
                'app_instance' => $instance?->name,
            ],
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

    private function forbidden(
        Node $servingNode,
        AppInstance $instance,
        AuthorizationResult $result,
        string $permission,
    ): JsonResponse {
        return $this->authorizationFailed(
            "This node is not authorized for '{$permission}' on '{$servingNode->name}'.",
            [
                'reason' => $result->reason,
                'missing_permission' => $result->missingPermission,
                'serving_node' => $servingNode->name,
                'app_instance' => $instance->name,
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
