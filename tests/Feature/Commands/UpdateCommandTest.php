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
        && str_contains($process->command, 'COMPOSER_BIN="$(command -v composer || true)"')
        && str_contains($process->command, '"$COMPOSER_BIN" install --no-interaction')
        && str_contains($process->command, 'php artisan migrate --force'));
});
