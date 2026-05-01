<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('updates the local orbit checkout', function (): void {
    Process::fake();
    Process::preventStrayProcesses();

    $this->artisan('update')
        ->expectsOutputToContain('Updated local Orbit checkout.')
        ->assertSuccessful();

    Process::assertRan(fn ($process): bool => $process->path === base_path()
        && $process->command === 'git pull --ff-only && composer install --no-interaction && php artisan migrate --force');
});
