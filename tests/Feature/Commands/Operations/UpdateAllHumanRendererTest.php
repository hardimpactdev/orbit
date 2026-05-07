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
    DB::table('nodes')->insert([
        [
            'name' => 'gateway',
            'role' => 'gateway',
            'host' => 'gateway',
            'ssh_user' => 'gateway',
            'orbit_path' => '/home/gateway/orbit',
            'status' => 'active',
            'is_local' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'name' => 'beast',
            'role' => 'app',
            'host' => 'beast',
            'ssh_user' => 'nckrtl',
            'orbit_path' => '/home/nckrtl/orbit',
            'status' => 'active',
            'is_local' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
});

it('renders progress tree shape', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllHumanRemoteShell);

    $this->artisan('update:all')
        ->expectsOutputToContain('┌  Updating Orbit nodes')
        ->expectsOutputToContain('Pulling source - local')
        ->expectsOutputToContain('Installing dependencies - local')
        ->expectsOutputToContain('Running migrations - local')
        ->expectsOutputToContain('Done - local')
        ->expectsOutputToContain('Pulling source - beast')
        ->expectsOutputToContain('Done - beast')
        ->expectsOutputToContain('Successfully updated 2 nodes')
        ->assertSuccessful();
});

it('renders the full decorated tree immediately and alternates active frames', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllHumanRemoteShell(delayMicroseconds: 400_000));

    $output = new BufferedOutput(decorated: true);
    $exitCode = Artisan::call('update:all', [], $output);
    $buffer = $output->fetch();
    $plainBuffer = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $buffer) ?? $buffer;

    expect($exitCode)->toBe(0);
    expect($plainBuffer)->toContain('┌  Updating Orbit nodes');
    expect($plainBuffer)->toContain('Pulling source - local');
    expect($plainBuffer)->toContain('Pulling source - beast');
    expect($buffer)->toContain("\e[36m○\e[39m");
    expect($buffer)->toContain("\e[36m◉\e[39m");
    expect($buffer)->toContain("\e[97mPulling source - beast");
    expect($buffer)->toContain("\e[97mDone - local");
    expect($buffer)->not->toContain("\e[38;5;242mDone - local");
    expect($buffer)->toContain("\e[97mSuccessfully updated 2 nodes");
    expect($buffer)->not->toContain("\e[38;5;242mSuccessfully updated 2 nodes");
    expect($plainBuffer)->toContain('●  Done - local');
    expect($plainBuffer)->toContain('●  Done - beast');
    expect($plainBuffer)->toContain('Successfully updated 2 nodes');
});

it('renders success footer', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllHumanRemoteShell);

    $this->artisan('update:all')
        ->expectsOutputToContain('Done - local')
        ->expectsOutputToContain('Done - beast')
        ->expectsOutputToContain('Successfully updated 2 nodes')
        ->assertSuccessful();
});

it('streams gateway progress for control callers', function (): void {
    DB::table('nodes')->delete();
    DB::table('nodes')->insert([
        [
            'name' => 'NMBP',
            'role' => 'control',
            'host' => '10.6.0.3',
            'ssh_user' => 'nckrtl',
            'orbit_path' => '/Users/nckrtl/orbit',
            'status' => 'active',
            'is_local' => true,
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
        ->expectsOutputToContain('Pulling source - local')
        ->expectsOutputToContain('Installing dependencies - local')
        ->expectsOutputToContain('Running migrations - local')
        ->expectsOutputToContain('Done - local')
        ->expectsOutputToContain('Pulling source - gateway')
        ->expectsOutputToContain('Installing dependencies - gateway')
        ->expectsOutputToContain('Running migrations - gateway')
        ->expectsOutputToContain('Done - gateway')
        ->expectsOutputToContain('Pulling source - beast')
        ->expectsOutputToContain('Done - beast')
        ->expectsOutputToContain('Successfully updated 3 nodes')
        ->doesntExpectOutputToContain('Successfully updated 1 node')
        ->assertSuccessful();
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
        ->expectsOutputToContain('Done - local')
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

it('excludes control nodes from human output', function (): void {
    DB::table('nodes')->insert([
        [
            'name' => 'mini',
            'role' => 'control',
            'host' => 'mini',
            'ssh_user' => 'nckrtl',
            'orbit_path' => '/Users/nckrtl/orbit',
            'status' => 'active',
            'is_local' => false,
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

final class UpdateAllHumanRemoteShell implements RemoteShell
{
    public function __construct(
        private readonly int $exitCode = 0,
        private readonly string $stderr = '',
        private readonly int $delayMicroseconds = 0,
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
