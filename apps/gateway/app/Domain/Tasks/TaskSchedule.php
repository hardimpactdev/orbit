<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Console\Scheduling\GatewaySchedule;
use App\Jobs\RunScheduledCommand;
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
        GatewaySchedule::job($schedule, new RunScheduledCommand('problems:collect', 900))
            ->name('problems:collect')->everyTenMinutes()->when($enabled);
        $schedule->command('problems:collect --horizon')->everyTenMinutes()->withoutOverlapping(1)->when($enabled);
        $schedule->command('problems:file')->hourly()->withoutOverlapping(30)->when($enabled);
    }
}
