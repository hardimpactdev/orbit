<?php

declare(strict_types=1);

use App\Services\OrbitUpdater;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Process\PhpExecutableFinder;

uses(RefreshDatabase::class);

it('emits json success envelope with three step keys on happy path', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('update', ['--json' => true]);
    $output = Artisan::output();
    $payload = json_decode(trim($output), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKey('success.data.update')
        ->and($payload['success']['data']['update']['scope'])->toBe('local')
        ->and($payload['success']['data']['update']['target'])->toBe('local')
        ->and($payload['success']['data']['update']['status'])->toBe('completed');

    $steps = $payload['success']['data']['update']['steps'];
    $names = array_column($steps, 'name');

    expect($names)->toBe(['pull_source', 'install_dependencies', 'run_migrations']);

    foreach ($steps as $step) {
        expect($step['status'])->toBe('completed');
    }
});

it('renders step tree and success prose on human path', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    $this->artisan('update')
        ->expectsOutputToContain('Updating Orbit')
        ->expectsOutputToContain('Pull source')
        ->expectsOutputToContain('Install dependencies')
        ->expectsOutputToContain('Run migrations')
        ->expectsOutputToContain('Updated local Orbit checkout.')
        ->assertSuccessful();
});

it('records completed activity log entry on success', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    Artisan::call('update', ['--json' => true]);

    $entry = Activity::query()->first();

    expect($entry)->not->toBeNull()
        ->and($entry->event)->toBe('update')
        ->and($entry->properties->get('scope'))->toBe('local')
        ->and($entry->properties->get('target'))->toBe('local')
        ->and($entry->properties->get('status'))->toBe('completed');
});

it('emits local_update_failed json with failed step when pull_source fails', function (): void {
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
    $payload = json_decode(trim($output), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('local_update_failed')
        ->and($payload['error']['meta']['failed_step'])->toBe('pull_source')
        ->and($payload['error']['data']['output'])->toBe('merge conflict');
});

it('emits local_checkout_unavailable when git exits 128', function (): void {
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
    $payload = json_decode(trim($output), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('local_checkout_unavailable')
        ->and($payload['error']['meta']['path'])->toBe(base_path());
});

it('records failed activity log entry on step failure', function (): void {
    Process::fake([
        'git pull --ff-only' => Process::result(
            output: '',
            errorOutput: 'merge conflict',
            exitCode: 1,
        ),
    ]);
    Process::preventStrayProcesses();

    Artisan::call('update', ['--json' => true]);

    $entry = Activity::query()->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties->get('status'))->toBe('failed')
        ->and($entry->properties->get('failed_step'))->toBe('pull_source');
});

it('swaps OrbitUpdater via the container to exercise full boot path', function (): void {
    app()->instance(OrbitUpdater::class, new UpdateFakeUpdater);

    $fakeUpdater = app(OrbitUpdater::class);
    assert($fakeUpdater instanceof UpdateFakeUpdater);

    $exitCode = Artisan::call('update', ['--json' => true]);
    $output = Artisan::output();
    $payload = json_decode(trim($output), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($fakeUpdater->pullCalled)->toBeTrue()
        ->and($fakeUpdater->dependenciesCalled)->toBeTrue()
        ->and($fakeUpdater->migrationsCalled)->toBeTrue()
        ->and($payload['success']['data']['update']['steps'])->toHaveCount(3);
});

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

final class UpdateFakeUpdater extends OrbitUpdater
{
    public bool $pullCalled = false;

    public bool $dependenciesCalled = false;

    public bool $migrationsCalled = false;

    public function __construct()
    {
        parent::__construct(new PhpExecutableFinder);
    }

    public function pullSource(): ProcessResult
    {
        $this->pullCalled = true;

        return Process::result(output: 'Already up to date.', errorOutput: '', exitCode: 0);
    }

    public function installDependencies(): ProcessResult
    {
        $this->dependenciesCalled = true;

        return Process::result(output: '', errorOutput: '', exitCode: 0);
    }

    public function runMigrations(): ProcessResult
    {
        $this->migrationsCalled = true;

        return Process::result(output: 'Nothing to migrate.', errorOutput: '', exitCode: 0);
    }
}
