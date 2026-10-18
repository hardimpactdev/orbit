<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Console\Scheduling\GatewaySchedule;
use App\Domain\Problems\ProblemCollection;
use App\Domain\Problems\ProblemCollector;
use App\Jobs\Problems\PruneProblemBatches;
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
        $schedule->call(static fn () => app(ProblemCollection::class)->dispatch())
            ->name('problems:collect')->everyTenMinutes()->when($enabled);
        $schedule->call(static fn () => app(ProblemCollector::class)->collectHorizon())
            ->name('problems:horizon')->everyTenMinutes()->when($enabled);
        GatewaySchedule::job($schedule, new PruneProblemBatches)
            ->daily()->when($enabled);
        $schedule->command('problems:file')->hourly()->withoutOverlapping(30)->when($enabled);
    }
}
