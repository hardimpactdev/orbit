<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('renders progress tree shape', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    $this->artisan('update')
        ->expectsOutputToContain('┌  Updating Orbit')
        ->expectsOutputToContain('Pull source')
        ->expectsOutputToContain('Install dependencies')
        ->expectsOutputToContain('Run migrations')
        ->assertSuccessful();
});

it('renders success prose', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    $this->artisan('update')
        ->expectsOutputToContain('Updated local Orbit checkout.')
        ->assertSuccessful();
});

it('renders failed step prose', function (): void {
    Process::fake([
        'git pull --ff-only' => Process::result(
            output: '',
            errorOutput: 'merge conflict',
            exitCode: 1,
        ),
    ]);
    Process::preventStrayProcesses();

    $this->artisan('update')
        ->expectsOutputToContain('Failed to update local Orbit checkout.')
        ->assertFailed();
});

it('shows captured output on failure', function (): void {
    Process::fake([
        'git pull --ff-only' => Process::result(
            output: '',
            errorOutput: 'merge conflict',
            exitCode: 1,
        ),
    ]);
    Process::preventStrayProcesses();

    $this->artisan('update')
        ->expectsOutputToContain('merge conflict')
        ->assertFailed();
});

it('has no json envelope in human mode', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    $this->artisan('update')
        ->expectsOutputToContain('Updated local Orbit checkout.')
        ->assertSuccessful();
});
