<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Schedules\RemoveSchedule;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Models\Schedule;
use App\Services\Schedules\SchedulePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class ScheduleDestroyController
{
    public function __construct(
        private SchedulePayload $payload,
    ) {}

    public function __invoke(Request $request, string $name, RemoveSchedule $removeSchedule): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        try {
            $schedule = $this->payload->find($name, $this->stringQuery($request, 'app'), $this->stringQuery($request, 'node'), $caller);

            if (! $this->callerCanManageSchedule($caller, $schedule)) {
                return $this->error('authorization_failed', 'This node is not authorized to manage the selected schedule.', [
                    'caller_role' => $caller->role,
                ], 403);
            }

            $result = $removeSchedule->handle($schedule);
        } catch (GatewayApiException $e) {
            return $this->error($e->errorCode() ?? 'validation_failed', $e->getMessage(), $e->errorMeta(), $this->status($e), $e->errorData());
        }

        return response()->json(['success' => $result]);
    }

    private function callerCanManageSchedule(Node $caller, Schedule $schedule): bool
    {
        if ($caller->role === 'gateway') {
            return true;
        }

        $servingNodeId = $schedule->scope === 'app' ? $schedule->app?->node_id : $schedule->node_id;

        return is_int($servingNodeId) && DB::table('node_access')
            ->where('consumer_node_id', $caller->id)
            ->where('serving_node_id', $servingNodeId)
            ->exists();
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    private function error(string $code, string $message, array $meta, int $status, array $data = []): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
            'meta' => empty($meta) ? (object) [] : $meta,
        ];

        if ($data !== []) {
            $error['data'] = $data;
        }

        return response()->json(['error' => $error], $status);
    }

    private function status(GatewayApiException $e): int
    {
        return $e->errorCode() === 'schedule.not_found' ? 404 : 422;
    }
}
