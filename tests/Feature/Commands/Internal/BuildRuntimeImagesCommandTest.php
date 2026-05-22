<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('builds orbit-runtime and pulls the official Caddy image when neither exists locally', function (): void {
    Process::fake(function ($process) {
        if (is_string($process->command) && str_contains($process->command, 'docker image inspect')) {
            return Process::result(exitCode: 1);
        }

        return Process::result();
    });

    $this->artisan('orbit:internal:build-runtime-images')
        ->expectsOutputToContain('Built orbit-runtime:current.')
        ->expectsOutputToContain('Pulled caddy:2-alpine.')
        ->assertSuccessful();

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker build')
        && str_contains($process->command, 'docker/orbit-runtime/Dockerfile')
        && str_contains($process->command, 'orbit-runtime:current'));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker pull')
        && str_contains($process->command, "'caddy:2-alpine'"));

    Process::assertNotRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker build')
        && str_contains($process->command, 'caddy:2-alpine'));
});

it('skips images that already exist locally so docker run --pull never can start the container', function (): void {
    Process::fake(function ($process) {
        if (is_string($process->command) && str_contains($process->command, 'docker image inspect')) {
            return Process::result();
        }

        return Process::result();
    });

    $this->artisan('orbit:internal:build-runtime-images')
        ->expectsOutputToContain('Skipping orbit-runtime:current')
        ->expectsOutputToContain('Skipping caddy:2-alpine')
        ->assertSuccessful();

    Process::assertNotRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker build'));

    Process::assertNotRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker pull'));
});

it('forces a rebuild and re-pull when --force is passed regardless of cached images', function (): void {
    Process::fake([
        '*' => Process::result(),
    ]);

    $this->artisan('orbit:internal:build-runtime-images', ['--force' => true])
        ->expectsOutputToContain('Built orbit-runtime:current.')
        ->expectsOutputToContain('Pulled caddy:2-alpine.')
        ->assertSuccessful();

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker build')
        && str_contains($process->command, 'orbit-runtime:current'));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'docker pull')
        && str_contains($process->command, "'caddy:2-alpine'"));
});

it('reports a clear failure when docker build fails', function (): void {
    Process::fake(function ($process) {
        if (is_string($process->command) && str_contains($process->command, 'docker image inspect')) {
            return Process::result(exitCode: 1);
        }

        return Process::result(errorOutput: 'docker build: out of disk space', exitCode: 1);
    });

    $this->artisan('orbit:internal:build-runtime-images')
        ->expectsOutputToContain('docker build: out of disk space')
        ->assertFailed();
});
