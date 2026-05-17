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

    DB::table('node_roles')->insert([
        'node_id' => DB::table('nodes')->where('name', 'beast')->value('id'),
        'role' => 'app-development',
        'status' => 'active',
        'settings' => json_encode(['tld' => 'test'], JSON_THROW_ON_ERROR),
        'last_error' => null,
        'converged_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
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

it('updates app nodes in parallel while preserving json target order', function (): void {
    DB::table('nodes')->insert([
        'name' => 'sidecar',
        'role' => 'app',
        'host' => 'sidecar',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('node_roles')->insert([
        'node_id' => DB::table('nodes')->where('name', 'sidecar')->value('id'),
        'role' => 'app-production',
        'status' => 'active',
        'settings' => json_encode([], JSON_THROW_ON_ERROR),
        'last_error' => null,
        'converged_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    $logPath = tempnam(sys_get_temp_dir(), 'orbit-update-all-json-parallel-');

    if ($logPath === false) {
        $this->fail('Could not create update timing log.');
    }

    try {
        app()->instance(RemoteShell::class, new UpdateAllJsonRemoteShell(logPath: $logPath, pullDelayMicroseconds: 500_000));

        $exitCode = Artisan::call('update:all', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        expect($exitCode)->toBe(0);
        expect(array_column($payload['success']['data']['updates'], 'target'))->toBe([
            'local',
            'beast',
            'sidecar',
        ]);

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expect($lines)->toBeArray();

        $events = array_map(
            fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines,
        );
        $pullEvents = array_values(array_filter(
            $events,
            fn (array $event): bool => ($event['script'] ?? null) === 'git pull --ff-only',
        ));

        expect($pullEvents)->toHaveCount(2);

        $latestStart = max(array_column($pullEvents, 'started_at'));
        $earliestEnd = min(array_column($pullEvents, 'ended_at'));

        expect($latestStart)->toBeLessThan($earliestEnd);
    } finally {
        @unlink($logPath);
    }
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

final readonly class UpdateAllJsonRemoteShell implements RemoteShell
{
    public function __construct(
        private int $exitCode = 0,
        private string $stderr = '',
        private ?string $logPath = null,
        private int $pullDelayMicroseconds = 0,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $startedAt = hrtime(true);

        if ($script === 'git pull --ff-only' && $this->pullDelayMicroseconds > 0) {
            usleep($this->pullDelayMicroseconds);
        }

        $endedAt = hrtime(true);

        if ($this->logPath !== null) {
            file_put_contents(
                $this->logPath,
                json_encode([
                    'node' => $node->name,
                    'script' => $script,
                    'started_at' => $startedAt,
                    'ended_at' => $endedAt,
                ], JSON_THROW_ON_ERROR).PHP_EOL,
                FILE_APPEND | LOCK_EX,
            );
        }

        return new RemoteShellResult(
            exitCode: $this->exitCode,
            stdout: '',
            stderr: $this->stderr,
            durationMs: (int) (($endedAt - $startedAt) / 1_000_000),
        );
    }
}
