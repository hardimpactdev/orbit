<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Console\Scheduling\GatewaySchedule;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Problems\ProblemCollector;
use App\Jobs\Problems\PruneProblemBatches;
use App\Jobs\TaskVms\ObserveParkedTaskVms;
use Illuminate\Console\Scheduling\Schedule;

final readonly class TaskSchedule
{
    public function register(Schedule $schedule): void
    {
        $enabled = static fn (): bool => app(TaskExtensionState::class)->enabled();
        GatewaySchedule::command($schedule, 'gateway:swamp-reader:check', uniqueFor: 300, overlapMinutes: 10, queue: GatewayQueue::Problems)->hourly()
            ->when(static fn (): bool => config()->string('swamp.read_token') !== '');
        GatewaySchedule::job($schedule, new ObserveParkedTaskVms)->everyMinute()->when($enabled);
        $schedule->command('problems:collect')->everyTenMinutes()->when($enabled);
        $schedule->call(static fn () => app(ProblemCollector::class)->collectHorizon())
            ->name('problems:horizon')->everyTenMinutes()->when($enabled);
        GatewaySchedule::job($schedule, new PruneProblemBatches)
            ->daily()->when($enabled);
        $schedule->command('problems:file')->hourly()->withoutOverlapping(30)->when($enabled);
    }
}
