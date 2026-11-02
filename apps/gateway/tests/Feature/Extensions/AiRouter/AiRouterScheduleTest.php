<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

// Its Caddy publication used the agent's caddy.publish, which #1901 removed, so a scheduled tick failed every minute.
it('schedules no AI router converge until its publication runs in Swamp', function (): void {
    $commands = collect(app(Schedule::class)->events())->pluck('command')->filter()->implode("\n");

    expect($commands)->toContain('artisan')->not->toContain('orbit:ai-router-converge');
});
