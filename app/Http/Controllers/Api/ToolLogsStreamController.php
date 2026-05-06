<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Controllers\Api\Concerns\ResolvesVisibleToolNodes;
use App\Models\Node;
use App\Services\Tools\ToolLogFollower;
use App\Services\Tools\ToolRegistryFailure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ToolLogsStreamController implements Loggable
{
    use ResolvesVisibleToolNodes;

    public function __invoke(Request $request, string $tool, ToolLogFollower $logs): JsonResponse|StreamedResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $visibleNodeIds = $this->visibleToolNodeIds($caller);

        if ($caller->role !== 'gateway' && $visibleNodeIds === []) {
            return $this->authorizationFailed('This node is not authorized to inspect tools.');
        }

        $authorizedTarget = $this->authorizedToolTarget($request, $caller, $visibleNodeIds);

        if ($authorizedTarget instanceof JsonResponse) {
            return $authorizedTarget;
        }

        $target = $logs->streamTarget(
            tool: $tool,
            node: $authorizedTarget['node'],
            app: $authorizedTarget['app'],
            lines: $this->positiveInteger($request, 'lines', 100),
        );

        if ($target instanceof ToolRegistryFailure) {
            return $this->failureResponse($target);
        }

        return response()->stream(function () use ($logs, $target, $tool): void {
            $logs->followTarget(
                tool: $tool,
                node: $target['node'],
                command: $target['command'],
                onOutput: function (string $output): void {
                    echo $output;

                    if (PHP_SAPI === 'fpm-fcgi') {
                        @ob_flush();
                        @flush();
                    }
                },
            );
        }, 200, [
            'Cache-Control' => 'no-cache',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Accel-Buffering' => 'no',
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
        return ActivityLogType::Read;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:GET /tools/{tool}/logs/stream';
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
