<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\UpdateAllGatewayStream;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\GatewayApiException;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => true]);
});

afterEach(function (): void {
    updateAllHumanSpinnerInterval(300_000);
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

    assignUpdateAllHumanAppHostRole('beast');
});

function assignUpdateAllHumanAppHostRole(string $nodeName, string $role = 'app-development'): void
{
    DB::table('node_roles')->insert([
        'node_id' => DB::table('nodes')->where('name', $nodeName)->value('id'),
        'role' => $role,
        'status' => 'active',
        'settings' => json_encode($role === 'app-development' ? ['tld' => 'test'] : [], JSON_THROW_ON_ERROR),
        'last_error' => null,
        'converged_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function updateAllHumanSpinnerInterval(int $microseconds): void
{
    config(['orbit.progress.frame_interval_us' => $microseconds]);
}

it('renders progress tree shape', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllHumanRemoteShell);

    $this->artisan('update:all')
        ->expectsOutputToContain('┌  Updating Orbit nodes')
        ->expectsOutputToContain('local Pulling source')
        ->expectsOutputToContain('local Installing dependencies')
        ->expectsOutputToContain('local Running migrations')
        ->expectsOutputToContain('local Done')
        ->expectsOutputToContain('beast Pulling source')
        ->expectsOutputToContain('beast Done')
        ->expectsOutputToContain('Successfully updated 2 nodes')
        ->assertSuccessful();
});

it('renders the full decorated tree immediately and alternates active frames', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    updateAllHumanSpinnerInterval(10_000);
    app()->instance(RemoteShell::class, new UpdateAllHumanRemoteShell(delayMicroseconds: 40_000));

    $output = new BufferedOutput(decorated: true);
    $exitCode = Artisan::call('update:all', [], $output);
    $buffer = $output->fetch();
    $plainBuffer = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $buffer) ?? $buffer;

    expect($exitCode)->toBe(0);
    expect($plainBuffer)->toContain('┌  Updating Orbit nodes');
    expect($plainBuffer)->toContain('local Pulling source');
    expect($plainBuffer)->toContain('beast Waiting');
    expect($plainBuffer)->toContain('beast Pulling source');
    expect($buffer)->toContain("\e[36m○\e[39m");
    expect($buffer)->toContain("\e[36m◉\e[39m");
    expect($buffer)->toContain("\e[38;5;242m○  beast Waiting");
    expect($buffer)->toContain("\e[97mbeast Pulling source");
    expect($buffer)->toContain("\e[97mlocal Done");
    expect($buffer)->not->toContain("\e[38;5;242mlocal Done");
    expect($buffer)->toContain("\e[97mSuccessfully updated 2 nodes");
    expect($buffer)->not->toContain("\e[38;5;242mSuccessfully updated 2 nodes");
    expect($plainBuffer)->toContain('●  local Done');
    expect($plainBuffer)->toContain('●  beast Done');
    expect($plainBuffer)->toContain('Successfully updated 2 nodes');
});

it('renders success footer', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllHumanRemoteShell);

    $this->artisan('update:all')
        ->expectsOutputToContain('local Done')
        ->expectsOutputToContain('beast Done')
        ->expectsOutputToContain('Successfully updated 2 nodes')
        ->assertSuccessful();
});

it('aligns update stages by the longest node name', function (): void {
    DB::table('nodes')->insert([
        [
            'name' => 'workspace-alpha',
            'role' => 'app',
            'host' => 'workspace-alpha',
            'orbit_path' => '/home/nckrtl/orbit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    assignUpdateAllHumanAppHostRole('workspace-alpha', 'app-production');

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllHumanRemoteShell);

    $output = new BufferedOutput(decorated: false);
    $exitCode = Artisan::call('update:all', [], $output);
    $buffer = $output->fetch();

    expect($exitCode)->toBe(0);
    expect($buffer)->toContain('local           Done');
    expect($buffer)->toContain('beast           Done');
    expect($buffer)->toContain('workspace-alpha Done');
});

it('streams gateway progress for control callers', function (): void {
    config(['orbit.is_gateway' => false]);

    DB::table('nodes')->delete();
    DB::table('nodes')->insert([
        [
            'name' => 'NMBP',
            'role' => 'control',
            'host' => '10.6.0.3',
            'orbit_path' => '/Users/nckrtl/orbit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    $stream = new UpdateAllHumanGatewayStream;
    app()->instance(UpdateAllGatewayStream::class, $stream);

    $this->artisan('update:all')
        ->expectsOutputToContain('┌  Updating Orbit nodes')
        ->expectsOutputToContain('local   Pulling source')
        ->expectsOutputToContain('local   Installing dependencies')
        ->expectsOutputToContain('local   Running migrations')
        ->expectsOutputToContain('local   Done')
        ->expectsOutputToContain('gateway Pulling source')
        ->expectsOutputToContain('gateway Installing dependencies')
        ->expectsOutputToContain('gateway Running migrations')
        ->expectsOutputToContain('gateway Done')
        ->expectsOutputToContain('beast   Pulling source')
        ->expectsOutputToContain('beast   Done')
        ->expectsOutputToContain('Successfully updated 3 nodes')
        ->doesntExpectOutputToContain('Successfully updated 1 node')
        ->assertSuccessful();
});

it('updates the control-local checkout while the gateway update is still running', function (): void {
    config(['orbit.is_gateway' => false]);

    DB::table('nodes')->delete();
    DB::table('nodes')->insert([
        [
            'name' => 'NMBP',
            'role' => 'control',
            'host' => '10.6.0.3',
            'orbit_path' => '/Users/nckrtl/orbit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();

    $logPath = tempnam(sys_get_temp_dir(), 'orbit-update-all-local-gateway-');

    if ($logPath === false) {
        $this->fail('Could not create local and gateway timing log.');
    }

    try {
        Process::fake(function ($process) use ($logPath) {
            $command = is_array($process->command)
                ? implode(' ', $process->command)
                : (string) $process->command;

            if ($command === 'git pull --ff-only') {
                file_put_contents($logPath, json_encode(['event' => 'local_pull_started'], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
            }

            return Process::result(output: '', errorOutput: '', exitCode: 0);
        });
        Process::preventStrayProcesses();

        app()->instance(UpdateAllGatewayStream::class, new UpdateAllHumanSlowGatewayStream($logPath));

        $this->artisan('update:all')
            ->expectsOutputToContain('local   Pulling source')
            ->expectsOutputToContain('gateway Pulling source')
            ->assertSuccessful();

        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expect($lines)->toBeArray();

        $events = array_map(
            fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines,
        );

        $localStartedAt = array_find_key($events, fn (array $event): bool => ($event['event'] ?? null) === 'local_pull_started');
        $gatewayDoneAt = array_find_key($events, fn (array $event): bool => ($event['event'] ?? null) === 'gateway_done');

        expect($localStartedAt)->not->toBeNull();
        expect($gatewayDoneAt)->not->toBeNull();
        expect($localStartedAt)->toBeLessThan($gatewayDoneAt);
    } finally {
        @unlink($logPath);
    }
});

it('renders failed local checkout prose', function (): void {
    Process::fake([
        'git pull --ff-only' => Process::result(
            output: '',
            errorOutput: 'merge conflict',
            exitCode: 1,
        ),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllHumanRemoteShell);

    $this->artisan('update:all')
        ->expectsOutputToContain('Failed to update local Orbit checkout.')
        ->expectsOutputToContain('merge conflict')
        ->assertFailed();
});

it('renders partial remote failure prose and continues', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllHumanRemoteShell(exitCode: 255, stderr: 'Permission denied (publickey).'));

    $this->artisan('update:all')
        ->expectsOutputToContain('local Done')
        ->expectsOutputToContain('Failed to update node beast.')
        ->expectsOutputToContain('Permission denied (publickey).')
        ->assertFailed();
});

it('renders failed update footer in red', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllHumanRemoteShell(exitCode: 255, stderr: 'Permission denied (publickey).'));

    $output = new BufferedOutput(decorated: true);
    $exitCode = Artisan::call('update:all', [], $output);
    $buffer = $output->fetch();

    expect($exitCode)->toBe(1);
    expect($buffer)->toContain("\e[31mFailed\e[39m");
    expect($buffer)->not->toContain("\e[38;5;242mFailed\e[39m");
});

it('has no json envelope in human mode', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllHumanRemoteShell);

    $this->artisan('update:all')
        ->expectsOutputToContain('Successfully updated 2 nodes')
        ->assertSuccessful();
});

it('excludes legacy control identities from human output', function (): void {
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

    app()->instance(RemoteShell::class, new UpdateAllHumanRemoteShell);

    $exitCode = Artisan::call('update:all');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect(str_contains($output, 'Successfully updated 2 nodes'))->toBeTrue();
    expect(str_contains($output, 'mini'))->toBeFalse();
});

final readonly class UpdateAllHumanRemoteShell implements RemoteShell
{
    public function __construct(
        private int $exitCode = 0,
        private string $stderr = '',
        private int $delayMicroseconds = 0,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if ($this->delayMicroseconds > 0) {
            usleep($this->delayMicroseconds);
        }

        return new RemoteShellResult(
            exitCode: $this->exitCode,
            stdout: '',
            stderr: $this->stderr,
            durationMs: 1,
        );
    }
}

final class UpdateAllHumanGatewayStream implements UpdateAllGatewayStream
{
    public int $calls = 0;

    public function run(callable $onEvent): int|GatewayApiException
    {
        $this->calls++;

        $onEvent('tree', [
            'title' => 'Updating Orbit nodes',
            'steps' => [
                ['key' => 'gateway', 'label' => 'Pulling source - gateway'],
                ['key' => 'beast', 'label' => 'Pulling source - beast'],
            ],
        ]);
        $onEvent('step', ['key' => 'gateway', 'status' => 'pulling_source', 'message' => 'Pulling source - gateway']);
        $onEvent('step', ['key' => 'gateway', 'status' => 'installing_dependencies', 'message' => 'Installing dependencies - gateway']);
        $onEvent('step', ['key' => 'gateway', 'status' => 'running_migrations', 'message' => 'Running migrations - gateway']);
        $onEvent('step', ['key' => 'gateway', 'status' => 'done', 'message' => 'Done - gateway']);
        $onEvent('step', ['key' => 'beast', 'status' => 'pulling_source', 'message' => 'Pulling source - beast']);
        $onEvent('step', ['key' => 'beast', 'status' => 'installing_dependencies', 'message' => 'Installing dependencies - beast']);
        $onEvent('step', ['key' => 'beast', 'status' => 'running_migrations', 'message' => 'Running migrations - beast']);
        $onEvent('step', ['key' => 'beast', 'status' => 'done', 'message' => 'Done - beast']);
        $onEvent('complete', [
            'exit_code' => 0,
            'data' => [
                'updates' => [
                    [
                        'target' => 'gateway',
                        'node' => 'gateway',
                        'role' => 'gateway',
                        'status' => 'completed',
                    ],
                    [
                        'target' => 'beast',
                        'node' => 'beast',
                        'role' => 'app',
                        'status' => 'completed',
                    ],
                ],
                'summary' => [
                    'total' => 2,
                    'completed' => 2,
                    'failed' => 0,
                ],
            ],
        ]);

        return 0;
    }
}

final readonly class UpdateAllHumanSlowGatewayStream implements UpdateAllGatewayStream
{
    public function __construct(
        private string $logPath,
    ) {}

    public function run(callable $onEvent): int|GatewayApiException
    {
        $onEvent('tree', [
            'title' => 'Updating Orbit nodes',
            'steps' => [
                ['key' => 'gateway', 'label' => 'Pulling source - gateway'],
                ['key' => 'beast', 'label' => 'Pulling source - beast'],
            ],
        ]);

        file_put_contents($this->logPath, json_encode(['event' => 'gateway_pull_started'], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        $onEvent('step', ['key' => 'gateway', 'status' => 'pulling_source', 'message' => 'Pulling source - gateway']);
        usleep(200_000);
        $onEvent('step', ['key' => 'gateway', 'status' => 'installing_dependencies', 'message' => 'Installing dependencies - gateway']);
        $onEvent('step', ['key' => 'gateway', 'status' => 'running_migrations', 'message' => 'Running migrations - gateway']);
        $onEvent('step', ['key' => 'gateway', 'status' => 'done', 'message' => 'Done - gateway']);
        file_put_contents($this->logPath, json_encode(['event' => 'gateway_done'], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);

        $onEvent('step', ['key' => 'beast', 'status' => 'pulling_source', 'message' => 'Pulling source - beast']);
        $onEvent('step', ['key' => 'beast', 'status' => 'done', 'message' => 'Done - beast']);
        $onEvent('complete', [
            'exit_code' => 0,
            'data' => [
                'updates' => [
                    [
                        'target' => 'gateway',
                        'node' => 'gateway',
                        'role' => 'gateway',
                        'status' => 'completed',
                    ],
                    [
                        'target' => 'beast',
                        'node' => 'beast',
                        'role' => 'app',
                        'status' => 'completed',
                    ],
                ],
                'summary' => [
                    'total' => 2,
                    'completed' => 2,
                    'failed' => 0,
                ],
            ],
        ]);

        return 0;
    }
}
