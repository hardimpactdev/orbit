<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\UpdateAllGatewayStream;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\Requests\Operations\UpdateAllRequest;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Services\OrbitUpdater;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

afterEach(fn (): null => MockClient::destroyGlobal());

// Gateway-role (is_gateway=true): runs local + queries registered nodes, no external gateway call.

beforeEach(function (): void {
    config(['orbit.is_gateway' => true]);

    DB::table('nodes')->insert([
        [
            'name' => 'gateway',
            'role' => 'gateway',
            'host' => 'gateway',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'name' => 'app-node-1',
            'role' => 'app',
            'host' => 'app-node-1',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
});

it('emits json success envelope when local and all nodes succeed', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllE2ERemoteShell);

    $exitCode = Artisan::call('update:all', ['--json' => true]);
    $output = Artisan::output();
    $payload = json_decode(trim($output), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKey('success.data.updates')
        ->and($payload['success']['data']['updates'])->toHaveCount(2);

    $local = $payload['success']['data']['updates'][0];
    expect($local)->toBe([
        'target' => 'local',
        'node' => null,
        'role' => null,
        'status' => 'completed',
    ]);

    $node = $payload['success']['data']['updates'][1];
    expect($node['target'])->toBe('app-node-1')
        ->and($node['status'])->toBe('completed');

    $summary = $payload['success']['meta']['summary'];
    expect($summary['total'])->toBe(2)
        ->and($summary['completed'])->toBe(2)
        ->and($summary['failed'])->toBe(0);
});

it('emits remote_update_failed with partial results when a node update fails', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllE2ERemoteShell(exitCode: 255, stderr: 'Permission denied (publickey).'));

    $exitCode = Artisan::call('update:all', ['--json' => true]);
    $output = Artisan::output();
    $payload = json_decode(trim($output), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('remote_update_failed')
        ->and($payload['error']['message'])->toBe('One or more Orbit installations failed to update.')
        ->and($payload['error']['data']['updates'])->toHaveCount(2)
        ->and($payload['error']['data']['updates'][0]['status'])->toBe('completed')
        ->and($payload['error']['data']['updates'][1]['status'])->toBe('failed');

    $summary = $payload['error']['meta']['summary'];
    expect($summary['total'])->toBe(2)
        ->and($summary['completed'])->toBe(1)
        ->and($summary['failed'])->toBe(1);
});

it('emits local_update_failed immediately without attempting node updates', function (): void {
    Process::fake([
        'git pull --ff-only' => Process::result(
            output: '',
            errorOutput: 'merge conflict',
            exitCode: 1,
        ),
    ]);
    Process::preventStrayProcesses();

    $shell = new UpdateAllE2ERemoteShell;
    app()->instance(RemoteShell::class, $shell);

    $exitCode = Artisan::call('update:all', ['--json' => true]);
    $output = Artisan::output();
    $payload = json_decode(trim($output), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('local_update_failed')
        ->and($payload['error']['data']['output'])->toBe('merge conflict')
        ->and($payload['error']['meta']['failed_step'])->toBe('local_checkout')
        ->and($shell->callCount)->toBe(0);
});

it('records fleet completed activity log on full success', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllE2ERemoteShell);

    Artisan::call('update:all', ['--json' => true]);

    $entry = Activity::query()->first();

    expect($entry)->not->toBeNull()
        ->and($entry->event)->toBe('update:all')
        ->and($entry->properties->get('scope'))->toBe('fleet')
        ->and($entry->properties->get('status'))->toBe('completed')
        ->and($entry->properties->get('summary'))->toBe([
            'total' => 2,
            'completed' => 2,
            'failed' => 0,
        ]);
});

it('records fleet failed activity log when a node update fails', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllE2ERemoteShell(exitCode: 1, stderr: 'ssh timeout'));

    Artisan::call('update:all', ['--json' => true]);

    $entry = Activity::query()->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties->get('status'))->toBe('failed')
        ->and($entry->properties->get('summary')['failed'])->toBe(1);
});

it('control caller fails when no gateway is configured', function (): void {
    config(['orbit.is_gateway' => false]);

    $exitCode = Artisan::call('update:all', ['--json' => true]);
    $output = Artisan::output();
    $payload = json_decode(trim($output), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('gateway_unavailable');
});

it('exercises full boot path using injected OrbitUpdater and RemoteShell fakes', function (): void {
    // Gateway JSON path calls pullSource/installDependencies/runMigrations individually for local,
    // then pullRemoteSource/installRemoteDependencies/runRemoteMigrations per node (3 calls/node).
    app()->instance(OrbitUpdater::class, new UpdateAllE2EFakeUpdater);

    $shell = new UpdateAllE2ERemoteShell;
    app()->instance(RemoteShell::class, $shell);

    $exitCode = Artisan::call('update:all', ['--json' => true]);
    $fakeUpdater = app(OrbitUpdater::class);
    assert($fakeUpdater instanceof UpdateAllE2EFakeUpdater);

    expect($exitCode)->toBe(0)
        ->and($fakeUpdater->pullCalled)->toBeTrue()
        ->and($fakeUpdater->dependenciesCalled)->toBeTrue()
        ->and($fakeUpdater->migrationsCalled)->toBeTrue()
        ->and($shell->callCount)->toBeGreaterThan(0);
});

// Control-role path: JSON path uses GatewayConnector (Saloon), not UpdateAllGatewayStream.

it('control caller succeeds when gateway responds with all nodes completed', function (): void {
    config(['orbit.is_gateway' => false]);

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();

    MockClient::global([
        UpdateAllRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'updates' => [
                        ['target' => 'gateway', 'node' => 'gateway', 'role' => 'gateway', 'status' => 'completed'],
                        ['target' => 'app-node-1', 'node' => 'app-node-1', 'role' => 'app', 'status' => 'completed'],
                    ],
                ],
                'meta' => [
                    'summary' => ['total' => 2, 'completed' => 2, 'failed' => 0],
                ],
            ],
        ], 200),
    ]);

    $exitCode = Artisan::call('update:all', ['--json' => true]);
    $output = Artisan::output();
    $payload = json_decode(trim($output), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['updates'])->toHaveCount(3)
        ->and($payload['success']['data']['updates'][0]['target'])->toBe('local')
        ->and($payload['success']['meta']['summary']['total'])->toBe(3)
        ->and($payload['success']['meta']['summary']['failed'])->toBe(0);
});

it('control caller reports partial failure when gateway returns failed nodes', function (): void {
    config(['orbit.is_gateway' => false]);

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();

    MockClient::global([
        UpdateAllRequest::class => MockResponse::make([
            'error' => [
                'code' => 'remote_update_failed',
                'message' => 'One or more Orbit installations failed to update.',
                'data' => [
                    'updates' => [
                        ['target' => 'gateway', 'node' => 'gateway', 'role' => 'gateway', 'status' => 'completed'],
                        ['target' => 'app-node-1', 'node' => 'app-node-1', 'role' => 'app', 'status' => 'failed', 'output' => 'Connection refused'],
                    ],
                ],
                'meta' => [
                    'summary' => ['total' => 2, 'completed' => 1, 'failed' => 1],
                ],
            ],
        ], 422),
    ]);

    $exitCode = Artisan::call('update:all', ['--json' => true]);
    $output = Artisan::output();
    $payload = json_decode(trim($output), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('remote_update_failed')
        ->and($payload['error']['data']['updates'])->toHaveCount(3)
        ->and($payload['error']['meta']['summary']['failed'])->toBeGreaterThan(0);
});

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

final class UpdateAllE2ERemoteShell implements RemoteShell
{
    public int $callCount = 0;

    public function __construct(
        private readonly int $exitCode = 0,
        private readonly string $stderr = '',
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->callCount++;

        return new RemoteShellResult(
            exitCode: $this->exitCode,
            stdout: '',
            stderr: $this->stderr,
            durationMs: 1,
        );
    }
}

final class UpdateAllE2EFakeUpdater extends OrbitUpdater
{
    public bool $pullCalled = false;

    public bool $dependenciesCalled = false;

    public bool $migrationsCalled = false;

    public function __construct() {}

    public function pullSource(): ProcessResult
    {
        $this->pullCalled = true;

        return Process::result(output: '', errorOutput: '', exitCode: 0);
    }

    public function installDependencies(): ProcessResult
    {
        $this->dependenciesCalled = true;

        return Process::result(output: '', errorOutput: '', exitCode: 0);
    }

    public function runMigrations(): ProcessResult
    {
        $this->migrationsCalled = true;

        return Process::result(output: '', errorOutput: '', exitCode: 0);
    }
}

final class UpdateAllE2EControlGatewayStream implements UpdateAllGatewayStream
{
    /**
     * @param  list<array<string, mixed>>  $updates
     */
    public function __construct(
        private readonly array $updates = [],
        private readonly ?string $errorMessage = null,
    ) {}

    public function run(callable $onEvent): int|GatewayApiException
    {
        if ($this->errorMessage !== null) {
            $onEvent('error', [
                'message' => $this->errorMessage,
                'data' => ['updates' => $this->updates],
            ]);

            return 1;
        }

        $onEvent('complete', [
            'exit_code' => 0,
            'data' => ['updates' => $this->updates],
        ]);

        return 0;
    }
}
