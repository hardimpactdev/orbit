<?php

declare(strict_types=1);

use App\Console\Commands\E2EDevTopologyCommand;
use App\E2E\Support\E2EDevTopologyManifestStore;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyLease;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->manifestDirectory = make_temp_directory('dev-topology-manifests');
    putenv("ORBIT_E2E_DEV_TOPOLOGY_MANIFEST_DIRECTORY={$this->manifestDirectory}");
});

afterEach(function (): void {
    putenv('ORBIT_E2E_DEV_TOPOLOGY_MANIFEST_DIRECTORY');
    remove_directory($this->manifestDirectory);
});

/**
 * Build a prepared-topology result the way acquireAndOverlay would return it,
 * without provisioning anything on a provider.
 *
 * @param  array<string, string>  $instances
 * @param  array<string, string>  $checkouts
 * @return array{host: string, run_id: string, source_path: string, ssh_key_path: string, gateway_ip: string, instances: array<string, string>, checkouts: array<string, string>}
 */
function fakePreparedTopology(
    string $runId = 'dev-abc123',
    string $host = 'beast',
    array $instances = [],
    array $checkouts = [],
    array $timings = [],
): array {
    $instances = $instances === []
        ? [
            'operator' => "orbit-e2e-{$runId}-operator",
            'gateway' => "orbit-e2e-{$runId}-gateway",
            'dev' => "orbit-e2e-{$runId}-dev",
        ]
        : $instances;

    $checkouts = $checkouts === []
        ? [
            'operator' => '/home/orbit/orbit-current',
            'gateway' => '/home/orbit/orbit-current',
            'dev' => '/home/orbit/orbit-current',
        ]
        : $checkouts;

    $topology = [
        'host' => $host,
        'run_id' => $runId,
        'source_path' => new \App\E2E\Support\SourceMountedCheckoutSyncer()->sourcePath(
            $host,
            'incus',
            $runId,
        ),
        'ssh_key_path' => "/tmp/orbit-e2e-topology-{$runId}/id_ed25519",
        'gateway_ip' => '10.6.0.2',
        'instances' => $instances,
        'checkouts' => $checkouts,
    ];

    if ($timings !== []) {
        $topology['timings'] = $timings;
    }

    return $topology;
}

function devTopologyCommandWith(callable $prepare): E2EDevTopologyCommand
{
    $command = app(E2EDevTopologyCommand::class);
    $command->prepareUsing(Closure::fromCallable($prepare));
    app()->instance(E2EDevTopologyCommand::class, $command);

    return $command;
}

it('renders a stable dry-run json contract without acquiring providers', function (): void {
    $result = run_e2e_script([
        PHP_BINARY,
        'bin/e2e-dev-topology',
        '--dry-run',
        '--json',
        '--kind=operator_gateway_app-dev',
        '--provider=docker',
    ]);

    expect($result['exit_code'])->toBe(0, $result['stderr']);

    $payload = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);

    expect($payload['success']['dev_topology']['id'])
        ->toBe('dry-run')
        ->and($payload['success']['dev_topology']['dry_run'])
        ->toBeTrue()
        ->and($payload['success']['dev_topology']['provider'])
        ->toBe('docker')
        ->and($payload['success']['dev_topology']['kind'])
        ->toBe('operator_gateway_app-dev')
        ->and($payload['success']['dev_topology']['checkout_roles'])
        ->toBe(['operator', 'gateway', 'app-dev'])
        ->and($payload['success']['dev_topology']['release_command'])
        ->toBe('composer e2e:dev-topology:release -- dry-run')
        ->and($payload['success']['dev_topology']['shell_command'])
        ->toContain('composer e2e:dev-topology -- --kind=operator_gateway_app-dev --provider=docker');
});

it('renders human dry-run output with the release command shape', function (): void {
    $result = run_e2e_script([
        PHP_BINARY,
        'bin/e2e-dev-topology',
        '--dry-run',
        '--kind=operator_gateway_app-dev',
        '--provider=docker',
    ]);

    expect($result['exit_code'])
        ->toBe(0, $result['stderr'])
        ->and($result['stdout'])
        ->toContain('Retained topology dry run')
        ->and($result['stdout'])
        ->toContain('Source-checkout E2E remains the normal feature loop')
        ->and($result['stdout'])
        ->toContain('composer e2e:dev-topology:release -- dry-run')
        ->and($result['stdout'])
        ->not->toContain('binary acceptance replaces');
});

it('honors explicit checkout-roles in the dry-run plan', function (): void {
    $result = run_e2e_script([
        PHP_BINARY,
        'bin/e2e-dev-topology',
        '--dry-run',
        '--json',
        '--kind=operator_gateway_app-dev_app-prod',
        '--provider=incus',
        '--checkout-roles=operator,gateway',
    ]);

    expect($result['exit_code'])->toBe(0, $result['stderr']);

    $payload = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);

    expect($payload['success']['dev_topology']['checkout_roles'])
        ->toBe(['operator', 'gateway'])
        ->and($payload['success']['dev_topology']['shell_command'])
        ->toContain('--checkout-roles=operator,gateway');
});

it('rejects unsupported topology kinds with a stable json error', function (): void {
    $result = run_e2e_script([
        PHP_BINARY,
        'bin/e2e-dev-topology',
        '--dry-run',
        '--json',
        '--kind=missing-kind',
    ]);

    expect($result['exit_code'])->toBe(1);

    $payload = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'error' => [
            'code' => 'validation_failed',
            'message' => 'Unsupported E2E topology kind [missing-kind].',
        ],
    ]);
});

it('persists a retained docker topology manifest and prints a provider release command', function (): void {
    devTopologyCommandWith(fn (E2ETopologyKind $kind, array $roles): array => fakePreparedTopology(
        host: 'local',
        checkouts: [
            'operator' => '/home/orbit/orbit',
            'gateway' => '/home/orbit/orbit',
            'dev' => '/home/orbit/orbit',
        ],
    ));

    $this
        ->artisan('e2e:dev-topology', [
            '--provider' => 'docker',
            '--kind' => 'operator_gateway_app-dev',
        ])
        ->expectsOutputToContain('Retained topology [dev-abc123] acquired.')
        ->expectsOutputToContain('Provider: docker (host local)')
        ->expectsOutputToContain('Release: composer e2e:dev-topology:release -- dev-abc123')
        ->assertSuccessful();

    $store = new E2EDevTopologyManifestStore($this->manifestDirectory);
    $manifest = $store->read('dev-abc123');

    expect($manifest)
        ->not
        ->toBeNull()
        ->and($manifest['provider'])
        ->toBe('docker')
        ->and($manifest['network'])
        ->toBe('orbit-e2e-dev-abc123')
        ->and($manifest['release_command'])
        ->toBe('composer e2e:dev-topology:release -- dev-abc123')
        ->and($manifest['managed_containers'])
        ->toContain('orbit-e2e-dev-abc123-gateway-orbit-gateway')
        ->and($manifest['managed_containers'])
        ->toContain('orbit-e2e-dev-abc123-dev-orbit-caddy')
        ->and($manifest['volumes'])
        ->toContain('orbit-e2e-dev-abc123-dev-etc-caddy');
});

it('routes composer dev topology scripts through apps e2e only', function (): void {
    $rootComposer = json_decode(
        (string) file_get_contents(repo_path('composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $e2eComposer = json_decode(
        (string) file_get_contents(repo_path('apps/e2e/composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $rootAcquire = implode("\n", (array) ($rootComposer['scripts']['e2e:dev-topology'] ?? []));
    $rootRelease = implode("\n", (array) ($rootComposer['scripts']['e2e:dev-topology:release'] ?? []));
    $e2eAcquire = implode("\n", (array) ($e2eComposer['scripts']['e2e:dev-topology'] ?? []));
    $e2eRelease = implode("\n", (array) ($e2eComposer['scripts']['e2e:dev-topology:release'] ?? []));

    expect($rootAcquire)
        ->toContain('composer --working-dir=apps/e2e e2e:dev-topology')
        ->and($rootRelease)
        ->toContain('composer --working-dir=apps/e2e e2e:dev-topology:release')
        ->and($e2eAcquire)
        ->toContain('php bin/e2e-dev-topology')
        ->and($e2eRelease)
        ->toContain('php bin/e2e-dev-topology-release')
        ->and($rootAcquire.$rootRelease.$e2eAcquire.$e2eRelease)
        ->not->toContain('orbit-gateway-artisan')->and($rootAcquire.$rootRelease.$e2eAcquire.$e2eRelease)
        ->not->toContain('apps/gateway/artisan');
});

it('persists a retained topology manifest and prints the release command', function (): void {
    devTopologyCommandWith(fn (E2ETopologyKind $kind, array $roles): array => fakePreparedTopology(
        timings: [
            ['name' => 'availability', 'seconds' => 0.111],
            ['name' => 'incus.source-sync', 'seconds' => 1.234],
            ['name' => 'checkout.overlay', 'seconds' => 2.345],
        ],
    ));

    $this
        ->artisan('e2e:dev-topology', [
            '--provider' => 'incus',
            '--kind' => 'operator_gateway_app-dev',
        ])
        ->expectsOutputToContain('Retained topology [dev-abc123] acquired.')
        ->expectsOutputToContain('Timings:')
        ->expectsOutputToContain('availability: 0.111s')
        ->expectsOutputToContain('incus.source-sync: 1.234s')
        ->expectsOutputToContain('checkout.overlay: 2.345s')
        ->expectsOutputToContain('composer e2e:incus -- --stop --id=dev-abc123')
        ->assertSuccessful();

    $store = new E2EDevTopologyManifestStore($this->manifestDirectory);
    $manifest = $store->read('dev-abc123');

    expect($manifest)
        ->not
        ->toBeNull()
        ->and($manifest['id'])
        ->toBe('dev-abc123')
        ->and($manifest['kind'])
        ->toBe('operator_gateway_app-dev')
        ->and($manifest['provider'])
        ->toBe('incus')
        ->and($manifest['host'])
        ->toBe('beast')
        ->and($manifest['run_id'])
        ->toBe('dev-abc123')
        ->and($manifest['gateway_ip'])
        ->toBe('10.6.0.2')
        ->and($manifest['ssh_key_path'])
        ->toBe('/tmp/orbit-e2e-topology-dev-abc123/id_ed25519')
        ->and($manifest['instances'])
        ->toMatchArray([
            'operator' => 'orbit-e2e-dev-abc123-operator',
            'gateway' => 'orbit-e2e-dev-abc123-gateway',
            'dev' => 'orbit-e2e-dev-abc123-dev',
        ])
        ->and($manifest['checkouts'])
        ->toHaveKey('operator')
        ->and($manifest['timings'])
        ->toBe([
            ['name' => 'availability', 'seconds' => 0.111],
            ['name' => 'incus.source-sync', 'seconds' => 1.234],
            ['name' => 'checkout.overlay', 'seconds' => 2.345],
        ])
        ->and($manifest['created_at'])
        ->toBeString();
});

it('reports source-mounted retained Incus checkouts in output and manifests', function (): void {
    devTopologyCommandWith(fn (E2ETopologyKind $kind, array $roles): array => fakePreparedTopology(
        checkouts: [
            'operator' => '/home/orbit/orbit',
            'gateway' => '/home/orbit/orbit',
            'dev' => '/home/orbit/orbit',
        ],
    ));

    $this
        ->artisan('e2e:dev-topology', [
            '--provider' => 'incus',
            '--kind' => 'operator_gateway_app-dev',
        ])
        ->expectsOutputToContain('Source-mounted checkout')
        ->expectsOutputToContain('/home/orbit/orbit/apps/cli/orbit')
        ->assertSuccessful();

    $store = new E2EDevTopologyManifestStore($this->manifestDirectory);
    $manifest = $store->read('dev-abc123');

    expect($manifest)
        ->not
        ->toBeNull()
        ->and($manifest['checkouts'])
        ->toBe([
            'operator' => '/home/orbit/orbit',
            'gateway' => '/home/orbit/orbit',
            'dev' => '/home/orbit/orbit',
        ])
        ->and($manifest['source_path'])
        ->toEndWith('/retained/dev-abc123');
});

it('reports source-mounted retained Incus runtime overlays in output and handles', function (): void {
    devTopologyCommandWith(fn (E2ETopologyKind $kind, array $roles): array => fakePreparedTopology(
        checkouts: [
            'operator' => '/home/orbit/orbit-run',
            'gateway' => '/home/orbit/orbit-run',
            'dev' => '/home/orbit/orbit-run',
        ],
    ));

    $exitCode = Artisan::call('e2e:dev-topology', [
        '--provider' => 'incus',
        '--kind' => 'operator_gateway_app-dev',
    ]);

    expect($exitCode)->toBe(0);

    $output = Artisan::output();

    expect($output)
        ->toContain('Source-mounted checkout: /home/orbit/orbit')
        ->toContain('Runtime checkout: /home/orbit/orbit-run')
        ->toContain('Launcher: /home/orbit/orbit-run/apps/cli/orbit')
        ->toContain('cd /home/orbit/orbit-run && orbit node:list --json');

    $store = new E2EDevTopologyManifestStore($this->manifestDirectory);
    $manifest = $store->read('dev-abc123');

    expect($manifest)
        ->not
        ->toBeNull()
        ->and($manifest['checkouts'])
        ->toBe([
            'operator' => '/home/orbit/orbit-run',
            'gateway' => '/home/orbit/orbit-run',
            'dev' => '/home/orbit/orbit-run',
        ]);
});

it('overlays app-dev and app-prod onto the canonical dev and prod roles', function (): void {
    $captured = [];

    devTopologyCommandWith(function (E2ETopologyKind $kind, array $roles) use (&$captured): array {
        $captured = $roles;

        return fakePreparedTopology();
    });

    $this->artisan('e2e:dev-topology', [
        '--provider' => 'incus',
        '--kind' => 'operator_gateway_app-dev_app-prod',
    ])->assertSuccessful();

    expect($captured)->toBe(['operator', 'gateway', 'dev', 'prod']);
});

it('maps retained manifests to every topology instance even when checkout roles are limited', function (): void {
    $command = app(E2EDevTopologyCommand::class);
    $lease = new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
        operator: devTopologyFakeInstance('orbit-e2e-dev-abc123-operator'),
        gateway: devTopologyFakeInstance('orbit-e2e-dev-abc123-gateway'),
        dev: devTopologyFakeInstance('orbit-e2e-dev-abc123-dev'),
        prod: devTopologyFakeInstance('orbit-e2e-dev-abc123-prod'),
        sshKeyPair: new SshKeyPair('/dev/null', '/dev/null'),
        rebuild: fn (): array => ['instances' => [], 'snapshotReset' => null],
        agent: devTopologyFakeInstance('orbit-e2e-dev-abc123-agent'),
    );

    $roles = (fn (E2ETopologyKind $kind): array => $this->manifestRolesForKind($kind))
        ->call($command, E2ETopologyKind::OperatorGatewayAppdevAppprodAgent);
    $instances = (fn (E2ETopologyLease $lease, array $roles): array => $this->instanceNamesByRole($lease, $roles))
        ->call($command, $lease, $roles);

    expect($roles)
        ->toBe(['operator', 'gateway', 'dev', 'prod', 'agent'])
        ->and($instances)
        ->toBe([
            'operator' => 'orbit-e2e-dev-abc123-operator',
            'gateway' => 'orbit-e2e-dev-abc123-gateway',
            'dev' => 'orbit-e2e-dev-abc123-dev',
            'prod' => 'orbit-e2e-dev-abc123-prod',
            'agent' => 'orbit-e2e-dev-abc123-agent',
        ]);
});

it('renders ssh and performance handles for app roles in json output', function (): void {
    devTopologyCommandWith(fn (E2ETopologyKind $kind, array $roles): array => fakePreparedTopology(
        timings: [
            ['name' => 'availability', 'seconds' => 0.111],
            ['name' => 'checkout.overlay', 'seconds' => 2.345],
        ],
    ));

    $exitCode = Artisan::call('e2e:dev-topology', [
        '--provider' => 'incus',
        '--kind' => 'operator_gateway_app-dev',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0);

    $output = trim(Artisan::output());
    $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    $devTopology = $payload['success']['dev_topology'];

    expect($devTopology['release_command'])
        ->toBe('composer e2e:incus -- --stop --id=dev-abc123')
        ->and($devTopology['handles'])
        ->toBeArray()
        ->and($devTopology['timings'])
        ->toBe([
            ['name' => 'availability', 'seconds' => 0.111],
            ['name' => 'checkout.overlay', 'seconds' => 2.345],
        ]);

    $byRole = collect($devTopology['handles'])->keyBy('role');

    expect($byRole['operator']['ssh_example'])
        ->toContain('incus exec orbit-e2e-dev-abc123-operator')
        ->and($byRole['operator']['ssh_example'])
        ->toContain('orbit node:list --json')
        // The gateway carries an immediate control-plane latency probe (CA
        // bootstrap over http, no auth) for "how fast is the setup responding".
        ->and($byRole['gateway']['perf_example'])
        ->toContain('time_total')
        ->and($byRole['gateway']['perf_example'])
        ->toContain('/api/ca/root')
        // app-dev is a FrankenPHP workload: surface its WireGuard endpoint and
        // honest guidance that an app must be deployed before it serves traffic.
        ->and($byRole['dev']['endpoint'])
        ->toContain('10.6.0.4')
        ->and($byRole['dev']['note'])
        ->toContain('orbit project:new')
        ->and($byRole['dev']['note'])
        ->toContain('time_total');
});

function devTopologyFakeInstance(string $name): E2EInstance
{
    return new class($name) implements E2EInstance {
        public function __construct(
            private string $name,
        ) {}

        public function name(): string
        {
            return $this->name;
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            throw new RuntimeException('Not used by retained topology command tests.');
        }

        public function ssh(
            string $user,
            SshKeyPair $keyPair,
            string $command,
            ?int $timeoutSeconds = null,
        ): ProcessResult {
            throw new RuntimeException('Not used by retained topology command tests.');
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void {}

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '127.0.0.1';
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void {}
    };
}

it('renders ssh and performance handles in human output', function (): void {
    devTopologyCommandWith(fn (E2ETopologyKind $kind, array $roles): array => fakePreparedTopology());

    $exitCode = Artisan::call('e2e:dev-topology', [
        '--provider' => 'incus',
        '--kind' => 'operator_gateway_app-dev',
    ]);

    expect($exitCode)->toBe(0);

    $output = Artisan::output();

    expect($output)
        ->toContain('[operator] orbit-e2e-dev-abc123-operator')
        ->and($output)
        ->toContain('[dev] orbit-e2e-dev-abc123-dev')
        ->and($output)
        ->toContain('Gateway API: http://10.6.0.2')
        ->and($output)
        ->toContain('Release: composer e2e:incus -- --stop --id=dev-abc123');
});
