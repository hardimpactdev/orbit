<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Models\Schedule;
use App\Services\Schedules\SchedulePayload;

final readonly class RemoveSchedule
{
    public function __construct(
        private SchedulePayload $payload,
    ) {}

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function handle(Schedule $schedule): array
    {
        $schedule->loadMissing(['app.node.schedulerState', 'node.schedulerState']);

        $targetNode = $schedule->scope === 'app' ? $schedule->app?->node : $schedule->node;
        $pickupConfirmed = $targetNode instanceof Node && $targetNode->schedulerState?->heartbeat_at !== null;

        $schedule->forceFill([
            'enabled' => false,
            'status' => $pickupConfirmed ? 'removed' : 'removed_pending_pickup',
        ])->save();

        $serialized = $this->payload->forSchedule($schedule);
        $name = $schedule->name;
        $node = $targetNode->name ?? $schedule->target_name;

        $schedule->delete();

        if (! $pickupConfirmed) {
            throw new GatewayApiException(
                "Schedule '{$name}' was removed from gateway intent, but the Orbit Scheduler on node '{$node}' could not be confirmed reachable.",
                'schedule.scheduler_unreachable',
                ['next_command' => 'doctor --family=schedule'],
                errorData: ['schedule' => $serialized],
            );
        }

        return [
            'data' => ['schedule' => $serialized],
            'meta' => [
                'scheduler_pickup' => 'confirmed',
                'history_retained' => true,
            ],
        ];
    }
}
