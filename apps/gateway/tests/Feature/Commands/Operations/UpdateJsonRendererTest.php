<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

it('selects json renderer with --json flag', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('update', ['--json' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect(str_contains($output, '"success"'))->toBeTrue();
});

it('renders success envelope shape', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('update', ['--json' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect(str_contains($output, '"scope":"local"'))->toBeTrue();
    expect(str_contains($output, '"target":"local"'))->toBeTrue();
    expect(str_contains($output, '"status":"completed"'))->toBeTrue();
});

it('includes all three step identifiers in success', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('update', ['--json' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect(str_contains($output, '"name":"pull_source"'))->toBeTrue();
    expect(str_contains($output, '"name":"install_dependencies"'))->toBeTrue();
    expect(str_contains($output, '"name":"run_migrations"'))->toBeTrue();
});

it('renders local_update_failed with failed_step and output', function (): void {
    Process::fake([
        'git pull --ff-only' => Process::result(
            output: '',
            errorOutput: 'merge conflict',
            exitCode: 1,
        ),
    ]);
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('update', ['--json' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect(str_contains($output, '"code":"local_update_failed"'))->toBeTrue();
    expect(str_contains($output, '"failed_step":"pull_source"'))->toBeTrue();
    expect(str_contains($output, '"output":"merge conflict"'))->toBeTrue();
});

it('renders local_checkout_unavailable with path', function (): void {
    Process::fake([
        'git pull --ff-only' => Process::result(
            output: '',
            errorOutput: 'fatal: not a git repository',
            exitCode: 128,
        ),
    ]);
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('update', ['--json' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect(str_contains($output, '"code":"local_checkout_unavailable"'))->toBeTrue();
    expect(str_contains($output, '"path":"'.repo_path().'"'))->toBeTrue();
});

it('includes no extra fields in error envelope', function (): void {
    Process::fake([
        'git pull --ff-only' => Process::result(
            output: '',
            errorOutput: 'fatal: not a git repository',
            exitCode: 128,
        ),
    ]);
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('update', ['--json' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect(str_contains($output, '"error"'))->toBeTrue();
});
