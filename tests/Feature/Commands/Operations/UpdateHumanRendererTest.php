<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

it('renders progress tree shape', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('update');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect(str_contains($output, '┌ Update Orbit'))->toBeTrue();
    expect(str_contains($output, 'Pull source'))->toBeTrue();
    expect(str_contains($output, 'Install dependencies'))->toBeTrue();
    expect(str_contains($output, 'Run migrations'))->toBeTrue();
});

it('renders success prose', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('update');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect(str_contains($output, 'Updated local Orbit checkout.'))->toBeTrue();
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

    $exitCode = Artisan::call('update');
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect(str_contains($output, 'Failed to update local Orbit checkout.'))->toBeTrue();
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

    $exitCode = Artisan::call('update');
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect(str_contains($output, 'merge conflict'))->toBeTrue();
});

it('has no json envelope in human mode', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('update');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect(str_contains($output, 'Updated local Orbit checkout.'))->toBeTrue();
    expect(str_contains($output, '"success"'))->toBeFalse();
});
