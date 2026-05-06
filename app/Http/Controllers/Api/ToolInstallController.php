<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Controllers\Api\Concerns\ResolvesVisibleToolNodes;
use App\Models\Node;
use App\Services\Tools\ToolInstaller;
use App\Services\Tools\ToolRegistryFailure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ToolInstallController implements Loggable
{
    use ResolvesVisibleToolNodes;

    private ?Node $activitySubject = null;

    public function __invoke(Request $request, string $tool, ToolInstaller $installer): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $visibleNodeIds = $this->visibleToolNodeIds($caller);

        if ($caller->role !== 'gateway' && $visibleNodeIds === []) {
            return $this->authorizationFailed('This node is not authorized to manage tools.');
        }

        $node = $this->requestString($request, 'node');
        $app = $this->requestString($request, 'app');
        $status = (string) $request->input('status', 'installed');
        $version = $this->requestString($request, 'version');
        $toolConfig = $request->input('config', []);

        if (! is_array($toolConfig)) {
            $toolConfig = [];
        }

        $result = $installer->install(
            tool: $tool,
            node: $node,
            app: $app,
            expectedVersion: $version,
            expectedState: $status,
            config: $toolConfig,
        );

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

    private function requestString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function failureResponse(ToolRegistryFailure $failure): JsonResponse
    {
        $status = match ($failure->code) {
            'tool.not_found' => 404,
            'authorization_failed' => 403,
            'validation_failed' => 422,
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
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /tools/{tool}/install';
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
