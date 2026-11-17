<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(LazilyRefreshDatabase::class);

// Artisan discovers no commands (App\Console\Kernel): each provider registers its own. A scheduled command that no provider
// registers fails at every run, and with the Gateway queue only the log says so. The removal reaper (orbit:instance-removal-reap) did this in release 2135.
it('registers every Artisan command that the Gateway schedule runs', function (): void {
    // Without the Gateway queue the schedule runs each command itself, so each event names its command.
    config(['queue.gateway.connection' => null]);
    Artisan::call('schedule:list');
    $registered = array_keys(Artisan::all());

    $scheduled = collect(app(Schedule::class)->events())
        ->map(static fn ($event): ?string => $event->command !== null && preg_match("/artisan'?\\s+'?([\\w:-]+)/", $event->command, $match) === 1 ? $match[1] : null)
        ->filter()->unique()->values()->all();

    expect($scheduled)->toContain('orbit:instance-removal-reap')
        ->and(array_values(array_diff($scheduled, $registered)))->toBe([]);
});
