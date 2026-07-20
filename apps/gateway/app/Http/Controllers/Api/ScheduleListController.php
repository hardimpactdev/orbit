<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Controllers\Api\Concerns\LogsScheduleApiActivity;
use App\Models\Node;
use App\Services\Schedules\SchedulePayload;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Sdk\Laravel\GatewayApiException;

final readonly class ScheduleListController implements Loggable
{
    use LogsScheduleApiActivity;

    public function __construct(
        private SchedulePayload $payload,
    ) {}

    #[OpenApiResponse(
        status: 200,
        description: 'The visible schedule inventory with concrete instance targets.',
        type: 'array{success: array{data: array{schedules: list<array{name: string, scope: string, target: array{type: string, name: string, node: string|null}, interval: string, timezone: string, execution: array{type: string, value: string}, enabled: bool, status: string, scheduler: array{node: string|null, heartbeat_at: string|null, registry_synced_at: string|null}, last_run: array{id: int, status: string, exit_code: int|null, started_at: string, finished_at: string|null}|null}>}, meta: array{instance: string|null, node: string|null, count: int}}}',
    )]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->fail('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        try {
            $data = $this->payload->list(
                instance: $this->stringQuery($request, 'instance'),
                node: $this->stringQuery($request, 'node'),
                caller: $caller,
            );
        } catch (GatewayApiException $e) {
            return $this->fail(
                code: $e->errorCode() ?? 'validation_failed',
                message: $e->getMessage(),
                meta: $e->errorMeta(),
                status: $this->status($e),
            );
        }

        return response()->json([
            'success' => [
                'data' => ['schedules' => $data['schedules']],
                'meta' => $data['meta'],
            ],
        ]);
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function fail(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], $status);
    }

    private function status(GatewayApiException $e): int
    {
        return $e->errorCode() === 'authorization_failed' ? 403 : 422;
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function type(): string
    {
        return 'api:GET /schedules';
    }
}
