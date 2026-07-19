<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Apps\AppWorkerService;
use App\Services\Nodes\Access\AuthorizationResult;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AppWorkerController implements Loggable
{
    private ?AppInstance $activitySubject = null;

    private string $currentAction = 'show';

    public function __construct(
        private readonly AppSelectorResolver $selectorResolver,
        private readonly WorkspacePlacement $placement,
        private readonly NodeAccessAuthorizer $authorizer,
    ) {}

    public function show(string $app, Request $request, AppWorkerService $service): JsonResponse
    {
        return $this->dispatch('show', 'app:read', $app, $request, $service);
    }

    public function enable(string $app, Request $request, AppWorkerService $service): JsonResponse
    {
        return $this->dispatch('enable', 'app:worker', $app, $request, $service);
    }

    public function disable(string $app, Request $request, AppWorkerService $service): JsonResponse
    {
        return $this->dispatch('disable', 'app:worker', $app, $request, $service);
    }

    private function dispatch(
        string $action,
        string $permission,
        string $selector,
        Request $request,
        AppWorkerService $service,
    ): JsonResponse {
        $this->currentAction = $action;

        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', status: 403);
        }

        $instanceIsVisible = fn (AppInstance $instance): bool => $this->selectorResolver->instanceIsVisibleTo(
            $caller,
            $instance,
            $permission,
        );

        try {
            $selection = $this->selectorResolver->resolve($selector, $instanceIsVisible);

            if ($selection === null) {
                return $this->error('app.not_found', "App '{$selector}' not found.", ['app' => $selector], 404);
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
            return $this->forbidden($node, $authorization, $permission);
        }

        $this->activitySubject = $instance;

        if ($action === 'show') {
            return $this->success($this->workerPayload($selection->app, $instance));
        }

        if ($action === 'enable') {
            $result = $service->enable($selection->app, $instance);

            if (! $result['ready']) {
                $readiness = $result['readiness'];

                return $this->error(
                    $readiness->code ?? 'app.worker_readiness_failed',
                    $readiness->message
                    ?? "App instance '{$selection->app->name}.{$instance->name}' is not ready for worker mode.",
                    array_merge([
                        'app' => $selection->app->name,
                        'app_instance' => $instance->name,
                        'missing' => $readiness->missing,
                    ], $readiness->meta),
                );
            }

            return $this->success(array_merge(
                $this->workerPayload($selection->app, $result['instance']),
                ['changed' => $result['changed']],
            ));
        }

        $result = $service->disable($instance);

        return $this->success(array_merge(
            $this->workerPayload($selection->app, $result['instance']),
            ['changed' => $result['changed']],
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function success(array $data): JsonResponse
    {
        return response()->json(['success' => ['data' => $data]]);
    }

    /**
     * @return array{app: string, app_instance: string, worker_enabled: bool, worker_config: array<string, mixed>|null}
     */
    private function workerPayload(App $app, AppInstance $instance): array
    {
        return [
            'app' => $app->name,
            'app_instance' => $instance->name,
            'worker_enabled' => $instance->worker_enabled,
            'worker_config' => is_array($instance->worker_config) ? $instance->worker_config : null,
        ];
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

    private function forbidden(Node $servingNode, AuthorizationResult $result, string $permission): JsonResponse
    {
        return $this->error(
            'authorization_failed',
            "This node is not authorized for '{$permission}' on '{$servingNode->name}'.",
            [
                'reason' => $result->reason,
                'missing_permission' => $result->missingPermission,
                'serving_node' => $servingNode->name,
            ],
            403,
        );
    }

    public function effect(): ActivityLogType
    {
        return $this->currentAction === 'show' ? ActivityLogType::Read : ActivityLogType::Write;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return match ($this->currentAction) {
            'enable' => 'api:POST /apps/{app}/worker/enable',
            'disable' => 'api:POST /apps/{app}/worker/disable',
            default => 'api:GET /apps/{app}/worker',
        };
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
        return ['action' => $this->currentAction];
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
