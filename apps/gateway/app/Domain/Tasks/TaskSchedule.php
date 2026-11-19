<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Console\Scheduling\GatewaySchedule;
use App\Domain\Gateway\GatewayQueue;
use Illuminate\Console\Scheduling\Schedule;

final readonly class TaskSchedule
{
    public function register(Schedule $schedule): void
    {
        $enabled = static fn (): bool => app(TaskExtensionState::class)->enabled();
        GatewaySchedule::command($schedule, 'gateway:swamp-reader:check', uniqueFor: 300, overlapMinutes: 10, queue: GatewayQueue::Problems)->hourly()
            ->when(static fn (): bool => config()->string('swamp.read_token') !== '');
        // The gate check of stage 4 only reads and admits; its bound covers the GitHub reads of one tick.
        GatewaySchedule::command($schedule, 'task:pr-gate', uniqueFor: 300, overlapMinutes: 5)->everyMinute()->when($enabled);
        // A task copy in pr_when_done publishes its done check by itself; the tick only reads records and admits the publish.
        GatewaySchedule::command($schedule, 'task:pr-when-done', uniqueFor: 300, overlapMinutes: 5)->everyMinute()->when($enabled);
        // A task copy whose publish conflicted gets the next step of its merge round; the tick only reads records and admits that step.
        GatewaySchedule::command($schedule, 'task:merge-round', uniqueFor: 300, overlapMinutes: 5)->everyMinute()->when($enabled);
        // A task copy group whose watched pull request merged completes by itself, in every Project; the tick only reads the pull request.
        GatewaySchedule::command($schedule, 'task:copy-settle', uniqueFor: 300, overlapMinutes: 5)->everyFiveMinutes()->when($enabled);
        // Task insights totals may be five minutes old; the tick reads only changed groups and the records.
        GatewaySchedule::command($schedule, 'task:insights-refresh', uniqueFor: 300, overlapMinutes: 5)->everyFiveMinutes()->when($enabled);
        $hours = config()->integer('retention.job_batch_hours');
        GatewaySchedule::command($schedule, 'queue:prune-batches', uniqueFor: 3600, overlapMinutes: 60, queue: GatewayQueue::Problems, arguments: ["--hours={$hours}", "--unfinished={$hours}", "--cancelled={$hours}"])
            ->daily()->when($enabled);
        $schedule->command('problems:file')->hourly()->withoutOverlapping(30)->when($enabled);
    }
}
