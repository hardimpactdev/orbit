<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Data\Schedules\SchedulerTickResult;
use Carbon\CarbonImmutable;

final readonly class OrbitScheduler
{
    public function tick(?CarbonImmutable $now = null): SchedulerTickResult
    {
        $startedAt = $now ?? CarbonImmutable::now();

        return new SchedulerTickResult(
            startedAt: $startedAt,
            finishedAt: CarbonImmutable::now(),
            dueSchedules: 0,
            executedSchedules: 0,
        );
    }

    public function secondsUntilNextMinute(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $seconds = (int) $now->format('s');

        if ($seconds === 0) {
            return 60;
        }

        return 60 - $seconds;
    }
}
