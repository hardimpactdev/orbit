<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('has no required inputs', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('update');

    expect($exitCode)->toBe(0);
});

it('has no prompts', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('update');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect(str_contains($output, 'Updated local Orbit checkout.'))->toBeTrue();
});

it('is local only with no remote side effects', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('update');

    expect($exitCode)->toBe(0);

    Process::assertRan(fn ($process): bool => $process->path === repo_path()
        && (is_string($process->command) && str_contains($process->command, 'git pull --ff-only')));
});

it('runs steps in order', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('update');

    expect($exitCode)->toBe(0);

    Process::assertRan(fn ($process): bool => is_string($process->command) && str_contains($process->command, 'git pull --ff-only'));
    Process::assertRan(fn ($process): bool => is_array($process->command) && collect($process->command)->contains(fn ($arg) => str_contains((string) $arg, 'composer')));
    Process::assertRan(fn ($process): bool => is_array($process->command) && collect($process->command)->contains('migrate'));
});

it('returns success exit code on success', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('update');

    expect($exitCode)->toBe(0);
});

it('logs local update activity', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('update', ['--json' => true]);

    $entry = Activity::query()->first();

    expect($exitCode)->toBe(0);
    expect($entry)->not->toBeNull();
    expect($entry->event)->toBe('update');
    expect($entry->properties->get('type'))->toBe('write');
    expect($entry->properties->get('scope'))->toBe('local');
    expect($entry->properties->get('target'))->toBe('local');
    expect($entry->properties->get('status'))->toBe('completed');
});

it('returns failure exit code on handled failure', function (): void {
    Process::fake([
        'git pull --ff-only' => Process::result(
            output: '',
            errorOutput: 'merge conflict',
            exitCode: 1,
        ),
    ]);
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('update');

    expect($exitCode)->toBe(1);
});
