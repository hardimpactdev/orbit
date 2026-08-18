<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Api\Concerns\ResolvesVisibleToolNodes;
use App\Models\Node;
use App\Services\Authorization\ServingNodeResolver;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolLogReader;
use App\Services\Tools\ToolRegistryFailure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** @mago-expect lint:too-many-methods */
final class ToolLogsController implements Loggable
{
    use ResolvesVisibleToolNodes;

    public function __invoke(
        Request $request,
        string $tool,
        ToolLogReader $logs,
        ToolCatalog $catalog,
    ): JsonResponse {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $target = $catalog->gatewayLocal($tool)
            ? $this->gatewayLocalTarget($request, $caller)
            : $this->remoteTarget($request, $caller, $tool);

        if ($target instanceof JsonResponse) {
            return $target;
        }

        $result = $logs->read(
            tool: $tool,
            node: $target['node'],
            app: $target['app'],
            lines: $this->positiveInteger($request, 'lines', 100),
        );

        if ($result instanceof ToolRegistryFailure) {
            return $this->failureResponse($result);
        }

        $lines = is_array($result['lines'] ?? null) ? $result['lines'] : [];

        return response()->json([
            'success' => [
                'data' => ['logs' => $result],
                'meta' => ['line_count' => count($lines)],
            ],
        ]);
    }

    /**
     * @return array{node: string, app: null}|JsonResponse
     */
    private function gatewayLocalTarget(
        Request $request,
        Node $caller,
    ): array|JsonResponse {
        $authorizer = app(NodeAccessAuthorizer::class);
        $servingNodeResolver = app(ServingNodeResolver::class);
        $gateway = $servingNodeResolver->resolve($request, ServingNode::Gateway);

        if (! $gateway instanceof Node) {
            return $this->authorizationFailed('Serving gateway could not be resolved for tool logs.', [
                'reason' => 'serving_node_unresolved',
                'missing_permission' => 'tool:logs',
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

        $authorization = $authorizer->authorize($caller, $gateway, 'tool:logs');

        if (! $authorization->allowed) {
            return $this->authorizationFailed(
                "This node is not authorized for 'tool:logs' on '{$gateway->name}'.",
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
    private function remoteTarget(Request $request, Node $caller, string $tool): array|JsonResponse
    {
        $visibleNodeIds = $this->visibleToolNodeIds($caller, true, 'tool:logs');

        if (! $this->nodeRoleAssignments()->nodeIsGateway($caller) && $visibleNodeIds === []) {
            return $this->authorizationFailed('This node is not authorized to inspect tools.');
        }

        return $this->authorizedToolTarget(
            $request,
            $caller,
            $visibleNodeIds,
            allowAnyActiveNode: true,
            tool: $tool,
        );
    }

    private function positiveInteger(Request $request, string $key, int $default): int
    {
        $value = $request->input($key, $default);

        return is_numeric($value) ? max(1, (int) $value) : $default;
    }

    private function failureResponse(ToolRegistryFailure $failure): JsonResponse
    {
        $status = match ($failure->code) {
            'tool.not_found' => 404,
            'authorization_failed' => 403,
            'node.agent_unreachable',
            'tool.runtime_missing',
            'tool.runtime_ambiguous',
            'tool.unsupported_on_node',
                => 422,
            'tool.remote_action_failed' => 502,
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

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function type(): string
    {
        return 'api:GET /tools/{tool}/logs';
    }

    public function subject(): ?Model
    {
        return null;
    }

    public function properties(): array
    {
        return [];
    }

    public function description(): ?string
    {
        return null;
    }
}
