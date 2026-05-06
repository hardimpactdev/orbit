<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Node;
use App\Models\Schedule;
use App\Services\Schedules\SchedulePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ScheduleSyncController
{
    public function __construct(
        private SchedulePayload $payload,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var mixed $resolvedUser */
        $resolvedUser = $request->user();
        $caller = $resolvedUser instanceof Node ? $resolvedUser : null;

        if (! $caller instanceof Node) {
            return response()->json([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'Peer identity unknown.',
                    'meta' => [],
                ],
            ], 403);
        }

        $schedules = Schedule::query()
            ->with(['app.node.schedulerState', 'node.schedulerState', 'latestRun'])
            ->where('enabled', true)
            ->where('status', 'expected')
            ->get()
            ->filter(fn (Schedule $schedule): bool => $this->targetsCaller($schedule, $caller))
            ->map(fn (Schedule $schedule): array => [
                'schedule_key' => $schedule->schedule_key,
                ...$this->payload->forSchedule($schedule),
            ])
            ->values()
            ->all();

        return response()->json([
            'success' => [
                'data' => ['schedules' => $schedules],
                'meta' => [
                    'node' => $caller->name,
                    'count' => count($schedules),
                ],
            ],
        ]);
    }

    private function targetsCaller(Schedule $schedule, Node $caller): bool
    {
        if ($schedule->scope === 'app') {
            return $schedule->app?->node_id === $caller->id;
        }

        if ($schedule->scope === 'node') {
            return $schedule->node_id === $caller->id;
        }

        return $schedule->scope === 'orbit' && $caller->role === 'gateway';
    }
}
