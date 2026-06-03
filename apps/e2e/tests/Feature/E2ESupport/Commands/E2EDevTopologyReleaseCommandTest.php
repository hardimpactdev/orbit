<?php

declare(strict_types=1);

use App\Console\Commands\E2EDevTopologyReleaseCommand;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EDevTopologyManifestStore;
use App\E2E\Support\IncusHost;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Mockery as m;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    $this->manifestDirectory = make_temp_directory('release-manifests');
    putenv("ORBIT_E2E_DEV_TOPOLOGY_MANIFEST_DIRECTORY={$this->manifestDirectory}");
});

afterEach(function (): void {
    putenv('ORBIT_E2E_DEV_TOPOLOGY_MANIFEST_DIRECTORY');
    remove_directory($this->manifestDirectory);
    m::close();
});

function devReleaseConfig(string $host = 'beast'): E2EConfig
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

function recordingIncusHost(E2EConfig $config, ArrayObject $log): IncusHost
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

function writeRetainedManifest(string $directory, string $id, string $host = 'beast'): void
{
    (new E2EDevTopologyManifestStore($directory))->write([
        'id' => $id,
        'kind' => 'operator_gateway_app-dev',
        'provider' => 'incus',
        'host' => $host,
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

function writeRetainedDockerManifest(string $directory, string $id, string $host = 'local'): void
{
    (new E2EDevTopologyManifestStore($directory))->write([
        'id' => $id,
        'kind' => 'operator_gateway_app-dev',
        'provider' => 'docker',
        'host' => $host,
        'run_id' => $id,
        'ssh_key_path' => '/dev/null',
        'gateway_ip' => '10.6.0.2',
        'network' => "orbit-e2e-{$id}",
        'instances' => [
            'operator' => "orbit-e2e-{$id}-operator",
            'gateway' => "orbit-e2e-{$id}-gateway",
            'dev' => "orbit-e2e-{$id}-dev",
        ],
        'managed_containers' => [
            "orbit-e2e-{$id}-operator-orbit-caddy",
            "orbit-e2e-{$id}-operator",
            "orbit-e2e-{$id}-gateway-orbit-gateway",
            "orbit-e2e-{$id}-gateway-orbit-caddy",
            "orbit-e2e-{$id}-gateway",
            "orbit-e2e-{$id}-dev-orbit-caddy",
            "orbit-e2e-{$id}-dev",
        ],
        'volumes' => [
            "orbit-e2e-{$id}-operator-home-orbit",
            "orbit-e2e-{$id}-operator-etc-caddy",
            "orbit-e2e-{$id}-gateway-home-orbit",
            "orbit-e2e-{$id}-gateway-etc-caddy",
            "orbit-e2e-{$id}-dev-home-orbit",
            "orbit-e2e-{$id}-dev-etc-caddy",
        ],
        'checkouts' => [
            'operator' => '/home/orbit/orbit',
        ],
        'resource_lease' => [
            'backend' => 'docker',
            'host' => $host,
            'slot' => 1,
            'path' => "{$directory}/docker-local-1.lease",
            'owner' => "retained-topology:{$id}",
            'retained' => true,
        ],
        'created_at' => '2026-05-30T10:00:00+00:00',
    ]);

    file_put_contents("{$directory}/docker-local-1.lease", json_encode([
        'backend' => 'docker',
        'host' => $host,
        'slot' => 1,
        'owner' => "retained-topology:{$id}",
        'pid' => null,
        'retained' => true,
        'created_at' => time(),
    ], JSON_THROW_ON_ERROR));
}

function releaseCommandWith(ArrayObject $log): E2EDevTopologyReleaseCommand
{
    $command = app(E2EDevTopologyReleaseCommand::class);
    $command->hostFactoryUsing(fn (string $host): IncusHost => recordingIncusHost(devReleaseConfig($host), $log));
    app()->instance(E2EDevTopologyReleaseCommand::class, $command);

    return $command;
}

it('reaps the recorded instances and removes the manifest and ssh key', function (): void {
    writeRetainedManifest($this->manifestDirectory, 'dev-abc123');

    $log = new ArrayObject(['deleted' => [], 'runs' => []]);
    releaseCommandWith($log);

    $this->artisan('e2e:dev-topology:release', ['id' => 'dev-abc123', '--json' => true])
        ->assertSuccessful();

    expect($log['deleted'])->toBe([[
        'orbit-e2e-dev-abc123-operator',
        'orbit-e2e-dev-abc123-gateway',
        'orbit-e2e-dev-abc123-dev',
    ]])
        // The dedicated per-run ssh key directory is removed on the host.
        ->and($log['runs'])->toContain("rm -rf '/tmp/orbit-e2e-topology-dev-abc123'")
        ->and((new E2EDevTopologyManifestStore($this->manifestDirectory))->read('dev-abc123'))->toBeNull();
});

it('releases docker retained topology resources from the manifest', function (): void {
    writeRetainedDockerManifest($this->manifestDirectory, 'dev-abc123');

    Process::fake(['*' => Process::result()]);

    $this->artisan('e2e:dev-topology:release', ['id' => 'dev-abc123', '--json' => true])
        ->assertSuccessful();

    Process::assertRan("docker rm -f 'orbit-e2e-dev-abc123-operator-orbit-caddy' 'orbit-e2e-dev-abc123-operator' 'orbit-e2e-dev-abc123-gateway-orbit-gateway' 'orbit-e2e-dev-abc123-gateway-orbit-caddy' 'orbit-e2e-dev-abc123-gateway' 'orbit-e2e-dev-abc123-dev-orbit-caddy' 'orbit-e2e-dev-abc123-dev' >/dev/null 2>&1 || true");
    Process::assertRan("docker volume rm -f 'orbit-e2e-dev-abc123-operator-home-orbit' 'orbit-e2e-dev-abc123-operator-etc-caddy' 'orbit-e2e-dev-abc123-gateway-home-orbit' 'orbit-e2e-dev-abc123-gateway-etc-caddy' 'orbit-e2e-dev-abc123-dev-home-orbit' 'orbit-e2e-dev-abc123-dev-etc-caddy' >/dev/null 2>&1 || true");
    Process::assertRan("docker network rm 'orbit-e2e-dev-abc123' >/dev/null 2>&1 || true");

    expect((new E2EDevTopologyManifestStore($this->manifestDirectory))->read('dev-abc123'))->toBeNull()
        ->and(is_file("{$this->manifestDirectory}/docker-local-1.lease"))->toBeFalse();
});

it('returns the reaped instances in the json payload', function (): void {
    writeRetainedManifest($this->manifestDirectory, 'dev-abc123');

    $log = new ArrayObject(['deleted' => [], 'runs' => []]);
    releaseCommandWith($log);

    $exitCode = Artisan::call('e2e:dev-topology:release', ['id' => 'dev-abc123', '--json' => true]);

    expect($exitCode)->toBe(0);

    $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['success']['released'])->toBe([[
        'id' => 'dev-abc123',
        'reaped' => [
            'orbit-e2e-dev-abc123-operator',
            'orbit-e2e-dev-abc123-gateway',
            'orbit-e2e-dev-abc123-dev',
        ],
        'dry_run' => false,
    ]]);
});

it('releases every recorded topology with --all', function (): void {
    writeRetainedManifest($this->manifestDirectory, 'dev-aaa111');
    writeRetainedManifest($this->manifestDirectory, 'dev-bbb222');

    $log = new ArrayObject(['deleted' => [], 'runs' => []]);
    releaseCommandWith($log);

    $this->artisan('e2e:dev-topology:release', ['--all' => true, '--json' => true])
        ->assertSuccessful();

    $store = new E2EDevTopologyManifestStore($this->manifestDirectory);

    expect($log['deleted'])->toHaveCount(2)
        ->and($store->list())->toBe([]);
});

it('treats releasing the dry-run placeholder as a no-op success', function (): void {
    $log = new ArrayObject(['deleted' => [], 'runs' => []]);
    releaseCommandWith($log);

    $this->artisan('e2e:dev-topology:release', ['id' => 'dry-run', '--json' => true])
        ->expectsOutputToContain('"id":"dry-run"')
        ->assertSuccessful();

    expect($log['deleted'])->toBe([]);
});

it('fails clearly when a retained topology manifest is missing', function (): void {
    $result = run_e2e_script(
        [PHP_BINARY, 'bin/e2e-dev-topology-release', 'missing-topology', '--json'],
        env: ['ORBIT_E2E_DEV_TOPOLOGY_MANIFEST_DIRECTORY' => $this->manifestDirectory],
    );

    expect($result['exit_code'])->toBe(1);

    $payload = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'error' => [
            'code' => 'not_found',
            'message' => 'Retained E2E topology [missing-topology] was not found.',
        ],
    ]);
});

it('requires an id when --all is not passed', function (): void {
    $log = new ArrayObject(['deleted' => [], 'runs' => []]);
    releaseCommandWith($log);

    $this->artisan('e2e:dev-topology:release', ['--json' => true])
        ->expectsOutputToContain('A retained E2E topology id is required')
        ->assertExitCode(1);
});
