<?php

declare(strict_types=1);

namespace App\Services\Processes;

use Carbon\CarbonImmutable;

final readonly class RuntimeHibernationSweepCadence
{
    private const int SECONDS_PER_MINUTE = 60;

    public function isDue(CarbonImmutable $now): bool
    {
        $intervalMinutes = max(
            1,
            (int) config('orbit.runtime_hibernation.sweep_interval_minutes', default: 10),
        );
        $epochMinute = intdiv($now->getTimestamp(), self::SECONDS_PER_MINUTE);

        return ($epochMinute % $intervalMinutes) === 0;
    }
}
