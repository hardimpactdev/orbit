<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Console\Scheduling\GatewaySchedule;
use Illuminate\Console\Scheduling\Schedule;

final readonly class TaskSchedule
{
    public function register(Schedule $schedule): void
    {
        $enabled = static fn (): bool => app(TaskExtensionState::class)->enabled();
        // The tick keeps its own lock of 300 seconds; a lost job frees its unique lock after 330.
        GatewaySchedule::command($schedule, 'tasks:tick', uniqueFor: 330)
            ->everyTenSeconds()
            ->when($enabled);
        GatewaySchedule::command($schedule, 'problems:collect', uniqueFor: 900, overlapMinutes: 15)
            ->everyTenMinutes()
            ->when($enabled);
        GatewaySchedule::command($schedule, 'problems:file', uniqueFor: 1800, overlapMinutes: 30)
            ->hourly()
            ->when($enabled);
    }
}
