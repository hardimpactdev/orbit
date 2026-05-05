<?php

declare(strict_types=1);

it('runs one scheduler daemon tick on demand', function (): void {
    $this->artisan('orbit-scheduler --once')
        ->expectsOutputToContain('Orbit Scheduler tick completed')
        ->assertSuccessful();
});
