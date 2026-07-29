<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\Process;
use App\Services\Processes\ProcessServiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

const PROCESS_BIND_CALLER_WG_IP = '10.6.0.91';

beforeEach(function (): void {
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
});

function createProcessBindCallerNode(): Node
{
    return createTestGatewayNode([
        'name' => 'caller',
        'host' => PROCESS_BIND_CALLER_WG_IP,
        'wireguard_address' => PROCESS_BIND_CALLER_WG_IP,
    ]);
}

function grantProcessBindAccess(Node $caller, Node $node, array $permissions): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $node->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  list<RemoteShellResult>  $results
 */
function processBindRemoteShell(array $results = []): ProcessBindRemoteShell
{
    $shell = new ProcessBindRemoteShell(
        $results === []
            ? [
                new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
                new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
                new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
                new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
                new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
                new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            ] : $results,
    );
    app()->instance(RemoteShell::class, $shell);

    return $shell;
}

final class ProcessBindRemoteShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

describe('process managed service binds', function (): void {
    it('creates a docker managed service with dual host publish binds', function (): void {
        createProcessBindCallerNode();
        $node = createTestAppHostNode([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.44',
        ]);
        processBindRemoteShell();

        $this
            ->call(
                'POST',
                '/api/processes',
                [
                    'node' => 'database-1',
                    'name' => 'valkey',
                    'service' => 'valkey',
                    'version' => '8',
                    'runtime' => 'docker',
                    'binds' => ['loopback', 'wireguard', 'wireguard'],
                    'start' => false,
                ],
                [],
                [],
                ['REMOTE_ADDR' => PROCESS_BIND_CALLER_WG_IP],
            )
            ->assertOk()
            ->assertJsonPath('success.data.process.name', 'valkey');

        $process = Process::query()->where('name', 'valkey')->firstOrFail();

        expect($process->runtime_config['binds'])
            ->toBe(['wireguard', 'loopback'])
            ->and($process->runtime_config['endpoint']['host'])
            ->toBe('10.6.0.44')
            ->and($process->runtime_config['endpoint']['port'])
            ->toBe(6379)
            ->and($process->runtime_config['ports'])
            ->toEqualCanonicalizing([
                [
                    'host' => '10.6.0.44',
                    'published' => 6379,
                    'target' => 6379,
                    'protocol' => 'tcp',
                ],
                [
                    'host' => '127.0.0.1',
                    'published' => 6379,
                    'target' => 6379,
                    'protocol' => 'tcp',
                ],
            ]);
    });

    it('defaults omitted binds to wireguard only on create', function (): void {
        createProcessBindCallerNode();
        createTestAppHostNode([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.44',
        ]);
        processBindRemoteShell();

        $this
            ->call(
                'POST',
                '/api/processes',
                [
                    'node' => 'database-1',
                    'name' => 'valkey',
                    'service' => 'valkey',
                    'version' => '8',
                    'runtime' => 'docker',
                    'start' => false,
                ],
                [],
                [],
                ['REMOTE_ADDR' => PROCESS_BIND_CALLER_WG_IP],
            )
            ->assertOk();

        $process = Process::query()->where('name', 'valkey')->firstOrFail();

        expect($process->runtime_config['binds'])
            ->toBe(['wireguard'])
            ->and($process->runtime_config['ports'])
            ->toHaveCount(1)
            ->and($process->runtime_config['ports'][0]['host'])
            ->toBe('10.6.0.44');
    });

    it('rejects loopback conflicts on the selected host and port pair', function (): void {
        createProcessBindCallerNode();
        $node = createTestAppHostNode([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.44',
        ]);
        Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'existing-valkey',
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => [
                    'service' => 'valkey',
                    'ports' => [
                        [
                            'host' => '127.0.0.1',
                            'published' => 6379,
                            'target' => 6379,
                            'protocol' => 'tcp',
                        ],
                    ],
                    'endpoint' => [
                        'name' => 'existing-valkey',
                        'kind' => 'tcp',
                        'host' => '127.0.0.1',
                        'port' => 6379,
                    ],
                ],
            ]);
        processBindRemoteShell();

        $this
            ->call(
                'POST',
                '/api/processes',
                [
                    'node' => 'database-1',
                    'name' => 'valkey',
                    'service' => 'valkey',
                    'version' => '8',
                    'runtime' => 'docker',
                    'binds' => ['loopback'],
                    'start' => false,
                ],
                [],
                [],
                ['REMOTE_ADDR' => PROCESS_BIND_CALLER_WG_IP],
            )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'endpoint_conflict')
            ->assertJsonPath('error.meta.host', '127.0.0.1')
            ->assertJsonPath('error.meta.port', 6379);

        expect(Process::query()->where('name', 'valkey')->exists())->toBeFalse();
    });

    it('updates bind intent while preserving service credentials and image', function (): void {
        createProcessBindCallerNode();
        $node = createTestAppHostNode([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.44',
        ]);
        $preservedVolumes = [
            [
                'name' => 'orbit-postgres',
                'target' => '/var/lib/postgresql/data',
            ],
        ];
        $preservedLabels = [
            'orbit.managed' => 'true',
            'orbit.process' => 'postgres',
            'orbit.process.service' => 'postgres',
            'orbit.process.version_family' => '16',
            'orbit.process.version' => '16-alpine',
            'orbit.process.spec_hash' => 'oldhash',
            'custom.label' => 'retained',
        ];
        $process = Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'postgres',
                'command' => 'postgres',
                'runtime' => ProcessRuntime::Docker,
                'credentials' => [
                    'database' => 'plausible_db',
                    'username' => 'orbit',
                    'password' => str_repeat('p', 32),
                    'environment' => [
                        'POSTGRES_PASSWORD' => str_repeat('p', 32),
                    ],
                ],
                'runtime_config' => [
                    'service' => 'postgres',
                    'version_family' => '16',
                    'version' => '16-alpine',
                    'image' => 'postgres:16-alpine',
                    'binds' => ['wireguard', 'loopback'],
                    'service_options' => [
                        'database' => 'plausible_db',
                        'username' => 'orbit',
                        'published_port' => 5432,
                    ],
                    'ports' => [
                        [
                            'host' => '10.6.0.44',
                            'published' => 5432,
                            'target' => 5432,
                            'protocol' => 'tcp',
                        ],
                        [
                            'host' => '127.0.0.1',
                            'published' => 5432,
                            'target' => 5432,
                            'protocol' => 'tcp',
                        ],
                    ],
                    'endpoint' => [
                        'name' => 'postgres',
                        'kind' => 'tcp',
                        'host' => '10.6.0.44',
                        'port' => 5432,
                    ],
                    'endpoints' => [
                        [
                            'name' => 'postgres',
                            'kind' => 'tcp',
                            'host' => '10.6.0.44',
                            'port' => 5432,
                        ],
                        [
                            'name' => 'postgres',
                            'kind' => 'tcp',
                            'host' => '127.0.0.1',
                            'port' => 5432,
                        ],
                    ],
                    'service_name' => 'orbit-postgres',
                    'command_mode' => 'image_entrypoint',
                    'credential_hash' => 'preserved-hash',
                    'operator_note' => 'keep-me',
                    'environment' => [
                        'POSTGRES_DB' => 'plausible_db',
                        'POSTGRES_USER' => 'orbit',
                    ],
                    'volumes' => $preservedVolumes,
                    'labels' => $preservedLabels,
                ],
            ]);
        processBindRemoteShell();

        $this
            ->call(
                'PATCH',
                '/api/processes/postgres',
                [
                    'node' => 'database-1',
                    'binds' => ['wireguard'],
                ],
                [],
                [],
                ['REMOTE_ADDR' => PROCESS_BIND_CALLER_WG_IP],
            )
            ->assertOk()
            ->assertJsonPath('success.data.changed', ['binds']);

        $process->refresh();
        $runtimeConfig = $process->runtime_config;

        $hashInput = $runtimeConfig;
        unset($hashInput['labels'], $hashInput['spec_hash']);
        $expectedSpecHash = app(ProcessServiceCatalog::class)->hashRuntimeSpec([
            ...$hashInput,
            'runtime' => ProcessRuntime::Docker->value,
            'process' => 'postgres',
        ]);

        $labelsWithoutHash = $runtimeConfig['labels'];
        unset($labelsWithoutHash['orbit.process.spec_hash']);
        $preservedLabelsWithoutHash = $preservedLabels;
        unset($preservedLabelsWithoutHash['orbit.process.spec_hash']);

        expect($process->credentials['password'])
            ->toBe(str_repeat('p', 32))
            ->and($process->getRawOriginal('credentials'))
            ->not->toBeNull()->and($runtimeConfig['image'])->toBe('postgres:16-alpine')->and(
                $runtimeConfig['service_options']['published_port'],
            )->toBe(5432)->and($runtimeConfig['command_mode'])->toBe('image_entrypoint')->and(
                $runtimeConfig['credential_hash'],
            )->toBe('preserved-hash')->and($runtimeConfig['operator_note'])->toBe('keep-me')->and(json_encode(
                $runtimeConfig['volumes'],
                JSON_THROW_ON_ERROR,
            ))->toBe(json_encode($preservedVolumes, JSON_THROW_ON_ERROR))->and(json_encode(
                $labelsWithoutHash,
                JSON_THROW_ON_ERROR,
            ))->toBe(json_encode($preservedLabelsWithoutHash, JSON_THROW_ON_ERROR))->and(
                $runtimeConfig['spec_hash'],
            )->toBe($expectedSpecHash)->and($runtimeConfig['labels']['orbit.process.spec_hash'])->toBe(
                $expectedSpecHash,
            )->and($runtimeConfig['labels']['orbit.process.spec_hash'])
            ->not->toBe('oldhash')->and($runtimeConfig['binds'])->toBe(['wireguard'])->and(
                $runtimeConfig['endpoint']['host'],
            )->toBe('10.6.0.44')->and($runtimeConfig['ports'])->toBe([
                [
                    'host' => '10.6.0.44',
                    'published' => 5432,
                    'target' => 5432,
                    'protocol' => 'tcp',
                ],
            ])->and(collect($runtimeConfig['ports'])->pluck('host')->all())
            ->not->toContain('127.0.0.1');
    });

    it('preserves existing binds when process:update omits binds', function (): void {
        createProcessBindCallerNode();
        $node = createTestAppHostNode([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.44',
        ]);
        Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'valkey',
                'command' => 'valkey-server',
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => [
                    'service' => 'valkey',
                    'version_family' => '8',
                    'version' => '8.1',
                    'binds' => ['wireguard', 'loopback'],
                    'service_name' => 'orbit-valkey',
                    'endpoint' => [
                        'name' => 'valkey',
                        'kind' => 'tcp',
                        'host' => '10.6.0.44',
                        'port' => 6379,
                    ],
                ],
            ]);
        processBindRemoteShell();

        $this
            ->call(
                'PATCH',
                '/api/processes/valkey',
                [
                    'node' => 'database-1',
                    'restart_policy' => 'always',
                ],
                [],
                [],
                ['REMOTE_ADDR' => PROCESS_BIND_CALLER_WG_IP],
            )
            ->assertOk();

        $process = Process::query()->where('name', 'valkey')->firstOrFail();

        expect($process->restart_policy->value)
            ->toBe('always')
            ->and($process->runtime_config['binds'])
            ->toBe(['wireguard', 'loopback']);
    });

    it('lists inferred and explicit binds with every endpoint', function (): void {
        createProcessBindCallerNode();
        $node = createTestAppHostNode([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.44',
        ]);
        Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'legacy-valkey',
                'command' => 'valkey-server',
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => [
                    'service' => 'valkey',
                    'version_family' => '8',
                    'version' => '8.1',
                    'service_name' => 'orbit-legacy-valkey',
                    'endpoint' => [
                        'name' => 'legacy-valkey',
                        'kind' => 'tcp',
                        'host' => '10.6.0.44',
                        'port' => 6379,
                    ],
                    'endpoints' => [
                        [
                            'name' => 'legacy-valkey',
                            'kind' => 'tcp',
                            'host' => '10.6.0.44',
                            'port' => 6379,
                        ],
                    ],
                ],
                'sort_order' => 1,
            ]);
        Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'dual-valkey',
                'command' => 'valkey-server',
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => [
                    'service' => 'valkey',
                    'version_family' => '8',
                    'version' => '8.1',
                    'binds' => ['wireguard', 'loopback'],
                    'service_name' => 'orbit-dual-valkey',
                    'endpoint' => [
                        'name' => 'dual-valkey',
                        'kind' => 'tcp',
                        'host' => '10.6.0.44',
                        'port' => 6380,
                    ],
                    'endpoints' => [
                        [
                            'name' => 'dual-valkey',
                            'kind' => 'tcp',
                            'host' => '10.6.0.44',
                            'port' => 6380,
                        ],
                        [
                            'name' => 'dual-valkey',
                            'kind' => 'tcp',
                            'host' => '127.0.0.1',
                            'port' => 6380,
                        ],
                    ],
                ],
                'sort_order' => 2,
            ]);

        $response = $this->call(
            'GET',
            '/api/processes?node=database-1',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_BIND_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.processes.0.service.binds', ['wireguard'])
            ->assertJsonPath('success.data.processes.1.service.binds', ['wireguard', 'loopback'])
            ->assertJsonPath('success.data.processes.1.service.endpoints.0.host', '10.6.0.44')
            ->assertJsonPath('success.data.processes.1.service.endpoints.1.host', '127.0.0.1');
    });
});
