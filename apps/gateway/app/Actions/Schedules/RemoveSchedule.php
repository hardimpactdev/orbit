<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

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
        $schedule->loadMissing(['app', 'appInstance', 'node']);
        $serialized = $this->payload->forSchedule($schedule);
        $serialized['status'] = 'removed';

        $schedule->delete();

        return [
            'data' => ['schedule' => $serialized],
            'meta' => ['history_retained' => true],
        ];
    }
}
