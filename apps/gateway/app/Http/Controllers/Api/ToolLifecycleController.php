<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Api\Concerns\ResolvesVisibleToolNodes;
use App\Http\Controllers\Api\Concerns\StreamsToolActionProgress;
use App\Models\Node;
use App\Services\Authorization\ServingNodeResolver;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolLifecycleManager;
use App\Services\Tools\ToolRegistryFailure;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ToolLifecycleController implements Loggable
{
    use ResolvesVisibleToolNodes;
    use StreamsToolActionProgress;

    private ?Node $activitySubject = null;

    public function __invoke(
        Request $request,
        string $tool,
        ToolLifecycleManager $lifecycle,
        ToolCatalog $catalog,
        ProgressEventStreamResponseFactory $streams,
    ): JsonResponse|StreamedResponse {
        $action = $request->route('action');

        if (! is_string($action)) {
            return $this->failureResponse(ToolRegistryFailure::unsupportedAction($tool, 'lifecycle'));
        }

        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $target = $catalog->gatewayLocal($tool)
            ? $this->gatewayLocalTarget($request, $caller, $action)
            : $this->remoteTarget($request, $caller, $tool, $action);

        if ($target instanceof JsonResponse) {
            return $target;
        }

        $node = $target['node'];
        $app = $target['app'];

        $agentSelfAuth = $this->authorizeAgentToolAction($caller, $node, $tool, $action);

        if ($agentSelfAuth instanceof JsonResponse) {
            return $agentSelfAuth;
        }

        $operation = static fn (): array|ToolRegistryFailure => $lifecycle->run(
            tool: $tool,
            action: $action,
            node: $node,
            app: $app,
        );
        $labels = $this->labelsForAction($action);

        if ($this->wantsEventStream($request)) {
            return $this->streamToolAction(
                streams: $streams,
                title: $labels['title'],
                doneFooter: $labels['done'],
                failFooter: $labels['fail'],
                operation: $operation,
                data: static fn (array $result): array => ['tool' => $result],
                exitCode: static fn (): int => 0,
            );
        }

        $result = $operation();

        if ($result instanceof ToolRegistryFailure) {
            return $this->failureResponse($result);
        }

        $this->activitySubject = $caller;

        return response()->json([
            'success' => [
                'data' => [
                    'tool' => $result,
                ],
                'meta' => (object) [],
            ],
        ]);
    }

    /**
     * @return array{title: string, done: string, fail: string}
     */
    private function labelsForAction(string $action): array
    {
        return match ($action) {
            'start' => ['title' => 'Starting Tool', 'done' => 'Tool started', 'fail' => 'Tool start failed'],
            'stop' => ['title' => 'Stopping Tool', 'done' => 'Tool stopped', 'fail' => 'Tool stop failed'],
            'restart' => ['title' => 'Restarting Tool', 'done' => 'Tool restarted', 'fail' => 'Tool restart failed'],
            'reload' => ['title' => 'Reloading Tool', 'done' => 'Tool reloaded', 'fail' => 'Tool reload failed'],
            default => ['title' => 'Running Tool', 'done' => 'Tool action completed', 'fail' => 'Tool action failed'],
        };
    }

    private function failureResponse(ToolRegistryFailure $failure): JsonResponse
    {
        $status = match ($failure->code) {
            'tool.not_found' => 404,
            'authorization_failed' => 403,
            'node.agent_unreachable',
            'validation_failed',
            'node_target_required',
            'tool.unsupported_on_node',
            'tool.runtime_missing',
            'tool.runtime_ambiguous',
                => 422,
            'tool.unsupported_action' => 400,
            default => 400,
        };

        return response()->json([
            'error' => [
                'code' => $failure->code,
                'message' => $failure->message,
                'meta' => $failure->meta === [] ? (object) [] : $failure->meta,
            ],
        ], $status);
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
                'meta' => $meta === [] ? (object) [] : $meta,
            ],
        ], 403);
    }

    /**
     * @return array{node: string, app: null}|JsonResponse
     */
    private function gatewayLocalTarget(
        Request $request,
        Node $caller,
        string $action,
    ): array|JsonResponse {
        $authorizer = app(NodeAccessAuthorizer::class);
        $servingNodeResolver = app(ServingNodeResolver::class);
        $gateway = $servingNodeResolver->resolve($request, ServingNode::Gateway);
        $permission = "tool:{$action}";

        if (! $gateway instanceof Node) {
            return $this->authorizationFailed('Serving gateway could not be resolved for this tool action.', [
                'reason' => 'serving_node_unresolved',
                'missing_permission' => $permission,
            ]);
        }

        $requestedNode = $this->toolTargetString($request, 'node');

        if ($requestedNode !== null && $requestedNode !== $gateway->name) {
            return $this->toolTargetValidationFailed(
                'node',
                $requestedNode,
                'Gateway-local tool actions target the active serving gateway only.',
            );
        }

        $authorization = $authorizer->authorize($caller, $gateway, $permission);

        if (! $authorization->allowed) {
            return $this->authorizationFailed(
                "This node is not authorized for '{$permission}' on '{$gateway->name}'.",
                [
                    'reason' => $authorization->reason,
                    'missing_permission' => $authorization->missingPermission,
                    'serving_node' => $gateway->name,
                ],
            );
        }

        return ['node' => $gateway->name, 'app' => null];
    }

    /**
     * @return array{node: ?string, app: ?string, resolved: ?Node}|JsonResponse
     */
    private function remoteTarget(Request $request, Node $caller, string $tool, string $action): array|JsonResponse
    {
        $visibleNodeIds = $this->visibleToolNodeIds($caller, true, "tool:{$action}");

        if (! $this->nodeRoleAssignments()->nodeIsGateway($caller) && $visibleNodeIds === []) {
            return $this->authorizationFailed('This node is not authorized to manage tools.');
        }

        return $this->authorizedToolTarget(
            $request,
            $caller,
            $visibleNodeIds,
            allowAnyActiveNode: true,
            tool: $tool,
        );
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /tools/{tool}/lifecycle';
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
