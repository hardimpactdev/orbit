<?php

declare(strict_types=1);

use App\Jobs\TaskVms\SweepTaskVmHosts;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

it('sweeps task VM hosts every thirty minutes as background maintenance', function (): void {
    // Resolving the scheduler through a console command runs the application's schedule definition.
    Artisan::call('schedule:list');
    $events = array_values(array_filter(app(Schedule::class)->events(),
        static fn ($event): bool => str_contains((string) $event->description, SweepTaskVmHosts::class)));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('*/30 * * * *');
});
