<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Node;
use App\Models\Schedule;
use App\Services\Schedules\SchedulePayload;

final readonly class AddSchedule
{
    public function __construct(
        private SchedulePayload $payload,
    ) {}

    /**
     * @return array{data: array<string, mixed>}
     */
    public function handle(App|Node $target, string $name, string $interval, string $timezone, string $executionType, string $executionValue): array
    {
        $scope = $target instanceof App ? 'app' : 'node';
        $targetName = $target->name;
        $targetNode = $target instanceof App ? $target->node : $target;
        $scheduleKey = "{$scope}:{$targetName}:{$name}";

        if (Schedule::query()->where('schedule_key', $scheduleKey)->exists()) {
            throw new GatewayApiException("Schedule '{$name}' already exists for {$scope} '{$targetName}'.", 'schedule.name_collision', [
                'name' => $name,
                $scope => $targetName,
            ]);
        }

        $schedule = Schedule::query()->create([
            'schedule_key' => $scheduleKey,
            'name' => $name,
            'scope' => $scope,
            'app_id' => $target instanceof App ? $target->id : null,
            'node_id' => $target instanceof Node ? $target->id : null,
            'target_name' => $targetName,
            'interval' => $interval,
            'timezone' => $timezone,
            'execution_type' => $executionType,
            'execution_value' => $executionValue,
            'enabled' => true,
            'status' => 'expected',
        ]);

        $serialized = $this->payload->forSchedule($schedule);

        if ($targetNode?->schedulerState?->heartbeat_at === null) {
            $schedule->forceFill(['status' => 'scheduler_unreachable'])->save();
            $serialized = $this->payload->forSchedule($schedule->refresh());
            $node = $targetNode->name ?? $targetName;

            throw new GatewayApiException(
                "Schedule '{$name}' was recorded, but the Orbit Scheduler on node '{$node}' could not be confirmed reachable.",
                'schedule.scheduler_unreachable',
                [
                    'node' => $node,
                    'next_command' => 'doctor --family=schedule',
                ],
                errorData: ['schedule' => $serialized],
            );
        }

        return [
            'data' => [
                'result' => [
                    'action' => 'created',
                    'scheduler_pickup' => 'confirmed',
                ],
                'schedule' => $serialized,
            ],
        ];
    }
}
