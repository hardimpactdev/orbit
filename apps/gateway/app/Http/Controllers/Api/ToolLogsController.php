<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Controllers\Api\Concerns\ResolvesVisibleToolNodes;
use App\Models\Node;
use App\Services\Tools\ToolLogReader;
use App\Services\Tools\ToolRegistryFailure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ToolLogsController implements Loggable
{
    use ResolvesVisibleToolNodes;

    public function __invoke(Request $request, string $tool, ToolLogReader $logs): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $visibleNodeIds = $this->visibleToolNodeIds($caller);

        if (! $this->nodeRoleAssignments()->nodeIsGateway($caller) && $visibleNodeIds === []) {
            return $this->authorizationFailed('This node is not authorized to inspect tools.');
        }

        $target = $this->authorizedToolTarget($request, $caller, $visibleNodeIds, allowOnlyVisibleFallback: false);

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

        return response()->json([
            'success' => [
                'data' => [
                    'logs' => $result,
                ],
                'meta' => (object) [],
            ],
        ]);
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
            'tool.remote_action_failed' => 502,
            'validation_failed' => 422,
            'tool.process_missing', 'tool.process_ambiguous' => 422,
            default => 400,
        };

        return response()->json([
            'error' => [
                'code' => $failure->code,
                'message' => $failure->message,
                'meta' => $this->failureMeta($failure),
            ],
        ], $status);
    }

    /**
     * @return array<string, mixed>|object
     */
    private function failureMeta(ToolRegistryFailure $failure): array|object
    {
        if (($failure->meta['field'] ?? null) === 'target') {
            return ['fields' => ['target']];
        }

        return $failure->meta === [] ? (object) [] : $failure->meta;
    }

    private function authorizationFailed(string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => $message,
                'meta' => [],
            ],
        ], 403);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:GET /tools/{tool}/logs';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return null;
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
        return [];
    }

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
