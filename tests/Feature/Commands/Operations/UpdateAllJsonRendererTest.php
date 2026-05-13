<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => true]);
});

beforeEach(function (): void {
    DB::table('nodes')->insert([
        [
            'name' => 'gateway',
            'role' => 'gateway',
            'host' => 'gateway',
            'orbit_path' => '/home/gateway/orbit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'name' => 'beast',
            'role' => 'app',
            'host' => 'beast',
            'orbit_path' => '/home/nckrtl/orbit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
});

it('selects json renderer with --json flag', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllJsonRemoteShell);

    $exitCode = Artisan::call('update:all', ['--json' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect(str_contains($output, '"success"'))->toBeTrue();
});

it('renders success envelope shape with updates array and summary', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllJsonRemoteShell);

    $exitCode = Artisan::call('update:all', ['--json' => true]);
    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(0);
    expect($payload)->toHaveKey('success.data.updates');
    expect($payload['success']['data']['updates'])->toHaveCount(2);
    expect($payload['success']['data']['updates'][0])->toBe([
        'target' => 'local',
        'node' => null,
        'role' => null,
        'status' => 'completed',
    ]);
    expect($payload['success']['data']['updates'][1])->toBe([
        'target' => 'beast',
        'node' => 'beast',
        'role' => 'app',
        'status' => 'completed',
    ]);
    expect($payload['success']['meta']['summary'])->toBe([
        'total' => 2,
        'completed' => 2,
        'failed' => 0,
    ]);
});

it('logs fleet update activity', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllJsonRemoteShell);

    $exitCode = Artisan::call('update:all', ['--json' => true]);

    $entry = Activity::query()->first();

    expect($exitCode)->toBe(0);
    expect($entry)->not->toBeNull();
    expect($entry->event)->toBe('update:all');
    expect($entry->properties->get('type'))->toBe('write');
    expect($entry->properties->get('scope'))->toBe('fleet');
    expect($entry->properties->get('status'))->toBe('completed');
    expect($entry->properties->get('summary'))->toBe([
        'total' => 2,
        'completed' => 2,
        'failed' => 0,
    ]);
    expect($entry->properties->get('targets'))->toHaveCount(2);
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

    app()->instance(RemoteShell::class, new UpdateAllJsonRemoteShell);

    $exitCode = Artisan::call('update:all', ['--json' => true]);
    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('local_update_failed');
    expect($payload['error']['data']['output'])->toBe('merge conflict');
    expect($payload['error']['meta']['failed_step'])->toBe('local_checkout');
});

it('renders remote_update_failed with partial target results and summary', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllJsonRemoteShell(exitCode: 255, stderr: 'Permission denied (publickey).'));

    $exitCode = Artisan::call('update:all', ['--json' => true]);
    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('remote_update_failed');
    expect($payload['error']['message'])->toBe('One or more Orbit installations failed to update.');
    expect($payload['error']['data']['updates'])->toHaveCount(2);
    expect($payload['error']['data']['updates'][0]['status'])->toBe('completed');
    expect($payload['error']['data']['updates'][1]['status'])->toBe('failed');
    expect($payload['error']['data']['updates'][1]['output'])->toBe('Permission denied (publickey).');
    expect($payload['error']['meta']['summary'])->toBe([
        'total' => 2,
        'completed' => 1,
        'failed' => 1,
    ]);
});

it('excludes control nodes from updates array', function (): void {
    DB::table('nodes')->insert([
        [
            'name' => 'mini',
            'role' => 'control',
            'host' => 'mini',
            'orbit_path' => '/Users/nckrtl/orbit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllJsonRemoteShell);

    $exitCode = Artisan::call('update:all', ['--json' => true]);
    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(0);
    expect($payload['success']['data']['updates'])->toHaveCount(2);

    $targets = array_column($payload['success']['data']['updates'], 'target');
    expect($targets)->not->toContain('mini');
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

    app()->instance(RemoteShell::class, new UpdateAllJsonRemoteShell);

    $exitCode = Artisan::call('update:all', ['--json' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect(str_contains($output, '"error"'))->toBeTrue();
});

final class UpdateAllJsonRemoteShell implements RemoteShell
{
    public function __construct(
        private readonly int $exitCode = 0,
        private readonly string $stderr = '',
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(
            exitCode: $this->exitCode,
            stdout: '',
            stderr: $this->stderr,
            durationMs: 1,
        );
    }
}
