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
        // Problem collection reports a stopped Horizon, so it runs in the scheduler, never on the queue
        // (docs/reference/gateway-queue.md#scheduled-work).
        $schedule->command('problems:collect')->everyTenMinutes()->withoutOverlapping(15)->when($enabled);
        $schedule->command('problems:file')->hourly()->withoutOverlapping(30)->when($enabled);
    }
}
