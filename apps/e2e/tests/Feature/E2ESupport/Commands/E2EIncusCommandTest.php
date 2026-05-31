<?php

declare(strict_types=1);

use App\Console\Commands\E2EDevTopologyCommand;
use App\Console\Commands\E2EDevTopologyReleaseCommand;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EDevTopologyManifestStore;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHost;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    $this->manifestDirectory = make_temp_directory('incus-command-manifests');
    putenv("ORBIT_E2E_DEV_TOPOLOGY_MANIFEST_DIRECTORY={$this->manifestDirectory}");
});

afterEach(function (): void {
    putenv('ORBIT_E2E_DEV_TOPOLOGY_MANIFEST_DIRECTORY');
    remove_directory($this->manifestDirectory);
});

/**
 * @return array{host: string, run_id: string, ssh_key_path: string, gateway_ip: string, instances: array<string, string>, checkouts: array<string, string>}
 */
function fakeIncusPreparedTopology(string $runId = 'dev-abc123'): array
{
    return [
        'host' => 'beast',
        'run_id' => $runId,
        'ssh_key_path' => "/tmp/orbit-e2e-topology-{$runId}/id_ed25519",
        'gateway_ip' => '10.6.0.2',
        'instances' => [
            'operator' => "orbit-e2e-{$runId}-operator",
            'gateway' => "orbit-e2e-{$runId}-gateway",
            'dev' => "orbit-e2e-{$runId}-dev",
        ],
        'checkouts' => [
            'operator' => '/home/orbit/orbit-current',
            'gateway' => '/home/orbit/orbit-current',
            'dev' => '/home/orbit/orbit-current',
        ],
    ];
}

function incusDevTopologyCommandWith(callable $prepare): void
{
    $command = app(E2EDevTopologyCommand::class);
    $command->prepareUsing(Closure::fromCallable($prepare));
    app()->instance(E2EDevTopologyCommand::class, $command);
}

function incusReleaseConfig(string $host = 'beast'): E2EConfig
{
    return new E2EConfig(
        providerNames: ['incus'],
        topologyProviderNames: ['incus'],
        host: $host,
        sourceImage: 'images:ubuntu/26.04/cloud',
        baseImage: 'orbit-base-ubuntu-26.04',
        bootstrapUser: 'provisioner',
        operatorUser: 'orbit',
        instancePrefix: 'orbit-e2e',
        timeoutSeconds: 600,
        cpus: '2',
        memory: '2GiB',
        topologyCpus: '1',
        topologyMemory: '2GiB',
        topologyRootSize: '16GiB',
        topologyStateSize: '4GiB',
        incusStoragePool: '',
        dockerHosts: ['local'],
        keep: false,
    );
}

function recordingIncusReleaseHost(E2EConfig $config, ArrayObject $log): IncusHost
{
    return new class($config, $log) extends IncusHost
    {
        public function __construct(E2EConfig $config, private readonly ArrayObject $log)
        {
            parent::__construct($config);
        }

        #[Override]
        public function deleteInstancesIfPresent(array $names): ProcessResult
        {
            $this->log['deleted'] = [...$this->log['deleted'], array_values($names)];

            return Process::result(output: '');
        }

        #[Override]
        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->log['runs'] = [...$this->log['runs'], $command];

            return Process::result(output: '');
        }
    };
}

function incusReleaseCommandWith(ArrayObject $log): void
{
    $command = app(E2EDevTopologyReleaseCommand::class);
    $command->hostFactoryUsing(fn (string $host): IncusHost => recordingIncusReleaseHost(incusReleaseConfig($host), $log));
    app()->instance(E2EDevTopologyReleaseCommand::class, $command);
}

function writeIncusRetainedManifest(string $directory, string $id): void
{
    (new E2EDevTopologyManifestStore($directory))->write([
        'id' => $id,
        'kind' => 'operator_gateway_app-dev',
        'provider' => 'incus',
        'host' => 'beast',
        'run_id' => $id,
        'ssh_key_path' => "/tmp/orbit-e2e-topology-{$id}/id_ed25519",
        'gateway_ip' => '10.6.0.2',
        'instances' => [
            'operator' => "orbit-e2e-{$id}-operator",
            'gateway' => "orbit-e2e-{$id}-gateway",
            'dev' => "orbit-e2e-{$id}-dev",
        ],
        'checkouts' => [
            'operator' => '/home/orbit/orbit-current',
        ],
        'created_at' => '2026-05-30T10:00:00+00:00',
    ]);
}

it('starts a retained Incus topology with the friendly command surface', function (): void {
    incusDevTopologyCommandWith(fn (E2ETopologyKind $kind, array $roles): array => fakeIncusPreparedTopology());

    $this->artisan('e2e:incus', [
        '--start' => true,
        '--topology' => 'operator_gateway_app-dev',
    ])
        ->expectsOutputToContain('Retained topology [dev-abc123] acquired.')
        ->expectsOutputToContain('Release: composer e2e:incus -- --stop --id=dev-abc123')
        ->assertSuccessful();

    $manifest = (new E2EDevTopologyManifestStore($this->manifestDirectory))->read('dev-abc123');

    expect($manifest)->not->toBeNull()
        ->and($manifest['kind'])->toBe('operator_gateway_app-dev')
        ->and($manifest['provider'])->toBe('incus');
});

it('renders dry-run json with the friendly start and stop command shapes', function (): void {
    $result = run_e2e_script([
        PHP_BINARY,
        'bin/e2e-incus',
        '--start',
        '--dry-run',
        '--json',
        '--topology=operator_gateway_app-dev',
    ]);

    expect($result['exit_code'])->toBe(0, $result['stderr']);

    $payload = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
    $devTopology = $payload['success']['dev_topology'];

    expect($devTopology['provider'])->toBe('incus')
        ->and($devTopology['kind'])->toBe('operator_gateway_app-dev')
        ->and($devTopology['shell_command'])->toBe('composer e2e:incus -- --start --topology=operator_gateway_app-dev')
        ->and($devTopology['release_command'])->toBe('composer e2e:incus -- --stop --id=dry-run');
});

it('stops a retained Incus topology by id', function (): void {
    writeIncusRetainedManifest($this->manifestDirectory, 'dev-abc123');

    $log = new ArrayObject(['deleted' => [], 'runs' => []]);
    incusReleaseCommandWith($log);

    $this->artisan('e2e:incus', [
        '--stop' => true,
        '--id' => 'dev-abc123',
        '--json' => true,
    ])->assertSuccessful();

    expect($log['deleted'])->toBe([[
        'orbit-e2e-dev-abc123-operator',
        'orbit-e2e-dev-abc123-gateway',
        'orbit-e2e-dev-abc123-dev',
    ]])
        ->and((new E2EDevTopologyManifestStore($this->manifestDirectory))->read('dev-abc123'))->toBeNull();
});

it('stops every retained Incus topology with all', function (): void {
    writeIncusRetainedManifest($this->manifestDirectory, 'dev-aaa111');
    writeIncusRetainedManifest($this->manifestDirectory, 'dev-bbb222');

    $log = new ArrayObject(['deleted' => [], 'runs' => []]);
    incusReleaseCommandWith($log);

    $this->artisan('e2e:incus', [
        '--stop' => true,
        '--all' => true,
        '--json' => true,
    ])->assertSuccessful();

    expect($log['deleted'])->toHaveCount(2)
        ->and((new E2EDevTopologyManifestStore($this->manifestDirectory))->list())->toBe([]);
});

it('rejects ambiguous start and stop mode selection', function (): void {
    $this->artisan('e2e:incus', [
        '--start' => true,
        '--stop' => true,
        '--json' => true,
    ])
        ->expectsOutputToContain('Choose exactly one Incus topology action: --start or --stop.')
        ->assertExitCode(1);
});

it('routes composer incus scripts through apps e2e only', function (): void {
    $rootComposer = json_decode((string) file_get_contents(repo_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $e2eComposer = json_decode((string) file_get_contents(repo_path('apps/e2e/composer.json')), true, flags: JSON_THROW_ON_ERROR);

    $rootIncus = implode("\n", (array) ($rootComposer['scripts']['e2e:incus'] ?? []));
    $e2eIncus = implode("\n", (array) ($e2eComposer['scripts']['e2e:incus'] ?? []));

    expect($rootIncus)->toContain('composer --working-dir=apps/e2e e2e:incus')
        ->and($e2eIncus)->toContain('php bin/e2e-incus')
        ->and($rootIncus.$e2eIncus)->not->toContain('orbit-gateway-artisan')
        ->and($rootIncus.$e2eIncus)->not->toContain('apps/gateway/artisan');
});
