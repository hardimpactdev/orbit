<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Models\App;
use App\Models\Instance;
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
    private ?Instance $activitySubject = null;

    private string $currentAction = 'show';

    public function __construct(
        private readonly AppSelectorResolver $selectorResolver,
        private readonly WorkspacePlacement $placement,
        private readonly NodeAccessAuthorizer $authorizer,
    ) {}

    public function show(string $instance, Request $request, AppWorkerService $service): JsonResponse
    {
        $app = $instance;

        return $this->dispatch('show', 'instance:read', $app, $request, $service);
    }

    public function enable(string $instance, Request $request, AppWorkerService $service): JsonResponse
    {
        $app = $instance;

        return $this->dispatch('enable', 'instance:worker', $app, $request, $service);
    }

    public function disable(string $instance, Request $request, AppWorkerService $service): JsonResponse
    {
        $app = $instance;

        return $this->dispatch('disable', 'instance:worker', $app, $request, $service);
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

        $instanceIsVisible = fn (Instance $instance): bool => $this->selectorResolver->instanceIsVisibleTo(
            $caller,
            $instance,
            $permission,
        );

        try {
            $selection = $this->selectorResolver->resolve($selector, $instanceIsVisible);

            if ($selection === null) {
                return $this->error(
                    'instance.not_found',
                    "Instance '{$selector}' not found.",
                    ['instance' => $selector],
                    404,
                );
            }

            $selection = $this->selectorResolver->requireInstance(
                $selection,
                instanceIsVisible: $instanceIsVisible,
            );
        } catch (AppSelectionResolutionFailed $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->meta);
        }

        $instance = $selection->instance;

        if (! $instance instanceof Instance) {
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
                    $readiness->code ?? 'instance.worker_readiness_failed',
                    $readiness->message
                    ?? "Instance '{$selection->app->name}.{$instance->name}' is not ready for worker mode.",
                    array_merge([
                        'app' => $selection->app->name,
                        'instance' => $instance->name,
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
     * @return array{app: string, instance: string, worker_enabled: bool, worker_config: array<string, mixed>|null}
     */
    private function workerPayload(App $app, Instance $instance): array
    {
        return [
            'app' => $app->name,
            'instance' => $instance->name,
            'worker_enabled' => $instance->worker_enabled,
            'worker_config' => is_array($instance->worker_config) ? $instance->worker_config : null,
        ];
    }

    private function instanceUnavailable(App $app, ?Instance $instance): JsonResponse
    {
        return $this->error(
            'validation_failed',
            "Instance '{$app->name}.{$instance?->name}' does not resolve an Orbit serving node.",
            [
                'field' => 'instance',
                'reason' => 'instance_unavailable',
                'app' => $app->name,
                'instance' => $instance?->name,
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

    public function type(): string
    {
        return match ($this->currentAction) {
            'enable' => 'api:POST /instances/{instance}/worker/enable',
            'disable' => 'api:POST /instances/{instance}/worker/disable',
            default => 'api:GET /instances/{instance}/worker',
        };
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
        return ['action' => $this->currentAction];
    }

    public function description(): ?string
    {
        return null;
    }
}
