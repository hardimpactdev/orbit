<?php

declare(strict_types=1);

use App\Console\Commands\E2EDevTopologyCommand;
use App\Console\Commands\E2EDevTopologyReleaseCommand;
use App\Console\Commands\E2EIncusCommand;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EDevTopologyManifestStore;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHost;
use App\E2E\Support\LiveIncusLocalMachine;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    Process::preventStrayProcesses();
    $this->manifestDirectory = make_temp_directory('incus-command-manifests');
    putenv("ORBIT_E2E_DEV_TOPOLOGY_MANIFEST_DIRECTORY={$this->manifestDirectory}");
});

afterEach(function (): void {
    putenv('ORBIT_E2E_DEV_TOPOLOGY_MANIFEST_DIRECTORY');
    putenv('ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT');
    putenv('ORBIT_E2E_LIVE_WG_ENDPOINT');
    unset($_ENV['ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT'], $_SERVER['ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT']);
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
        sourceImage: 'images:ubuntu/26.04',
        baseImage: 'orbit-base-ubuntu-26.04-runtime',
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

function recordingIncusLiveHost(E2EConfig $config, ArrayObject $log): IncusHost
{
    return new class($config, $log) extends IncusHost
    {
        public function __construct(E2EConfig $config, private readonly ArrayObject $log)
        {
            parent::__construct($config);
        }

        #[Override]
        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->log['runs'] = [...$this->log['runs'], $command];

            return Process::result(output: json_encode([
                'event' => 'complete',
                'data' => [
                    'exit_code' => 0,
                    'data' => [
                        'result' => [
                            'success' => [
                                'data' => [
                                    'node' => [
                                        'name' => 'mac-dev-abc123',
                                        'addresses' => [
                                            'wireguard' => '10.6.0.8',
                                        ],
                                    ],
                                    'wireguard' => [
                                        'config' => <<<'WG'
                                            [Interface]
                                            PrivateKey = test-private-key
                                            Address = 10.6.0.8/32

                                            [Peer]
                                            PublicKey = test-public-key
                                            AllowedIPs = 10.6.0.0/24
                                            Endpoint = 10.6.0.2:51820
                                            PersistentKeepalive = 25
                                            WG,
                                    ],
                                    'next_steps' => [
                                        'Install the WireGuard configuration on the operator node.',
                                        'Join the Orbit WireGuard network.',
                                        'Run `orbit gateway:add` on the operator node.',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        }
    };
}

function recordingLiveIncusLocalMachine(ArrayObject $log, bool $toolsAvailable = true): LiveIncusLocalMachine
{
    return new class($log, $toolsAvailable) extends LiveIncusLocalMachine
    {
        public function __construct(
            private readonly ArrayObject $log,
            private readonly bool $toolsAvailable,
        ) {}

        #[Override]
        public function hasWireGuardTools(): bool
        {
            return $this->toolsAvailable;
        }

        #[Override]
        public function wireGuardInterfaces(): array
        {
            return (array) ($this->log['interfaces'] ?? []);
        }

        #[Override]
        public function realWireGuardInterface(string $interface): ?string
        {
            $real = $this->log['real'] ?? [];

            return is_array($real) && is_string($real[$interface] ?? null)
                ? $real[$interface]
                : null;
        }

        #[Override]
        public function startWireGuard(string $configPath): ProcessResult
        {
            $this->log['local_runs'] = [...($this->log['local_runs'] ?? []), "wg-quick up {$configPath}"];
            $this->log['interfaces'] = ['utun42'];
            $this->log['real'] = [
                ...($this->log['real'] ?? []),
                'oe2eabc123' => 'utun42',
            ];

            return Process::result(output: '');
        }

        #[Override]
        public function stopWireGuard(string $configPath): ProcessResult
        {
            $this->log['local_runs'] = [...($this->log['local_runs'] ?? []), "wg-quick down {$configPath}"];

            return Process::result(output: '');
        }

        #[Override]
        public function addGateway(string $gatewayIp, string $gatewayName): ProcessResult
        {
            $this->log['local_runs'] = [...($this->log['local_runs'] ?? []), "orbit gateway:add {$gatewayIp} --name={$gatewayName} --json"];

            return Process::result(output: json_encode([
                'success' => [
                    'gateway' => [
                        'name' => $gatewayName,
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        #[Override]
        public function verifyGateway(string $gatewayIp): ProcessResult
        {
            $this->log['local_runs'] = [...($this->log['local_runs'] ?? []), "curl http://{$gatewayIp}/api/ca/root"];

            return Process::result(output: 'ok');
        }
    };
}

function incusLiveCommandWith(ArrayObject $log, ?LiveIncusLocalMachine $localMachine = null): void
{
    $command = app(E2EIncusCommand::class);
    $command->hostFactoryUsing(fn (string $host): IncusHost => recordingIncusLiveHost(incusReleaseConfig($host), $log));
    $command->localMachineUsing(fn (): LiveIncusLocalMachine => $localMachine ?? recordingLiveIncusLocalMachine($log));
    app()->instance(E2EIncusCommand::class, $command);
}

/**
 * @param  array<string, string>  $checkouts
 */
function writeIncusRetainedManifest(string $directory, string $id, array $checkouts = ['operator' => '/home/orbit/orbit-current']): void
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
        'checkouts' => $checkouts,
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

it('renders dry-run json for a dedicated ingress Incus topology', function (): void {
    $result = run_e2e_script([
        PHP_BINARY,
        'bin/e2e-incus',
        '--start',
        '--dry-run',
        '--json',
        '--topology=operator_gateway_app-dev_app-prod_ingress',
    ]);

    expect($result['exit_code'])->toBe(0, $result['stderr']);

    $payload = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
    $devTopology = $payload['success']['dev_topology'];

    expect($devTopology['kind'])->toBe('operator_gateway_app-dev_app-prod_ingress')
        ->and($devTopology['checkout_roles'])->toBe(['operator', 'gateway', 'app-dev', 'app-prod', 'ingress'])
        ->and($devTopology['shell_command'])->toBe('composer e2e:incus -- --start --topology=operator_gateway_app-dev_app-prod_ingress')
        ->and($devTopology['release_command'])->toBe('composer e2e:incus -- --stop --id=dry-run');
});

it('creates a live accessible Incus topology and prints local onboarding instructions', function (): void {
    putenv('ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT=192.168.1.150:51820');

    incusDevTopologyCommandWith(fn (E2ETopologyKind $kind, array $roles): array => fakeIncusPreparedTopology());

    $log = new ArrayObject(['runs' => [], 'local_runs' => []]);
    incusLiveCommandWith($log);

    $output = new BufferedOutput;
    $exitCode = app(Kernel::class)->call('e2e:incus', [
        '--live' => true,
        '--topology' => 'operator_gateway_app-dev_app-prod_ingress',
        '--json' => true,
    ], $output);

    $payload = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);
    $liveTopology = $payload['success']['live_topology'];

    $wireGuardConfigPath = "{$this->manifestDirectory}/oe2eabc123.conf";
    $manifest = (new E2EDevTopologyManifestStore($this->manifestDirectory))->read('dev-abc123');

    expect($exitCode)->toBe(0)
        ->and($liveTopology['id'])->toBe('dev-abc123')
        ->and($liveTopology['kind'])->toBe('operator_gateway_app-dev_app-prod_ingress')
        ->and($liveTopology['wireguard_endpoint'])->toBe('192.168.1.150:51820')
        ->and($liveTopology['wireguard']['endpoint'])->toBe('192.168.1.150:51820')
        ->and($liveTopology['wireguard']['interface'])->toBe('oe2eabc123')
        ->and($liveTopology['wireguard']['real_interface'])->toBe('utun42')
        ->and($liveTopology['wireguard']['config_path'])->toBe($wireGuardConfigPath)
        ->and($liveTopology['wireguard']['started'])->toBeTrue()
        ->and($liveTopology['wireguard']['gateway_added'])->toBeTrue()
        ->and($liveTopology['wireguard']['verified'])->toBeTrue()
        ->and($liveTopology['operator_node'])->toBe('mac-dev-abc123')
        ->and($liveTopology['gateway_add_command'])->toBe('orbit gateway:add 10.6.0.2 --name=incus-dev-abc123')
        ->and($liveTopology['gateway_use_command'])->toBe('orbit gateway:use incus-dev-abc123')
        ->and($liveTopology['release_command'])->toBe('composer e2e:incus -- --stop --id=dev-abc123')
        ->and($liveTopology['commands']['stop'])->toBe('composer e2e:incus -- --stop --id=dev-abc123')
        ->and($liveTopology['commands']['gateway_check'])->toBe('orbit node:list --json')
        ->and($liveTopology['next_steps'])->toContain('WireGuard tunnel [oe2eabc123] is active.')
        ->and($liveTopology['next_steps'])->toContain('Local gateway [incus-dev-abc123] is active.')
        ->and($wireGuardConfigPath)->toBeFile()
        ->and($manifest['kind'])->toBe('operator_gateway_app-dev_app-prod_ingress')
        ->and($manifest['live']['wireguard']['interface'])->toBe('oe2eabc123')
        ->and($manifest['live']['wireguard']['started'])->toBeTrue()
        ->and($manifest['live']['gateway']['name'])->toBe('incus-dev-abc123')
        ->and($manifest['live']['gateway']['added'])->toBeTrue()
        ->and(file_get_contents($wireGuardConfigPath))->toContain('Endpoint = 192.168.1.150:51820')
        ->and(file_get_contents($wireGuardConfigPath))->not->toContain('Endpoint = 10.6.0.2:51820')
        ->and($log['runs'][0])->toContain("incus exec 'orbit-e2e-dev-abc123-operator'")
        ->and($log['runs'][0])->toContain('orbit node:new mac-dev-abc123 --operator --json')
        ->and($log['local_runs'])->toContain("wg-quick up {$wireGuardConfigPath}")
        ->and($log['local_runs'])->toContain('orbit gateway:add 10.6.0.2 --name=incus-dev-abc123 --json')
        ->and($log['local_runs'])->toContain('curl http://10.6.0.2/api/ca/root');
});

it('reads live WireGuard endpoint from the PHP environment store', function (): void {
    $_ENV['ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT'] = '192.168.1.151:51820';

    incusDevTopologyCommandWith(fn (E2ETopologyKind $kind, array $roles): array => fakeIncusPreparedTopology());

    $log = new ArrayObject(['runs' => [], 'local_runs' => []]);
    incusLiveCommandWith($log);

    $output = new BufferedOutput;
    $exitCode = app(Kernel::class)->call('e2e:incus', [
        '--live' => true,
        '--manual' => true,
        '--json' => true,
    ], $output);

    $payload = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['live_topology']['wireguard_endpoint'])->toBe('192.168.1.151:51820')
        ->and($payload['success']['live_topology']['wireguard']['endpoint'])->toBe('192.168.1.151:51820');
});

it('prints the live WireGuard config and follow-up gateway commands in human mode', function (): void {
    putenv('ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT=192.168.1.150:51820');

    incusDevTopologyCommandWith(fn (E2ETopologyKind $kind, array $roles): array => fakeIncusPreparedTopology());

    $log = new ArrayObject(['runs' => []]);
    incusLiveCommandWith($log);

    $this->artisan('e2e:incus', [
        '--live' => true,
        '--topology' => 'operator_gateway_app-dev',
    ])
        ->expectsOutputToContain('Preparing live Incus topology')
        ->expectsOutputToContain('Validate live endpoint')
        ->expectsOutputToContain('Acquire topology')
        ->expectsOutputToContain('Mint local operator identity')
        ->expectsOutputToContain('Write WireGuard config')
        ->expectsOutputToContain('Start local tunnel')
        ->expectsOutputToContain('Add local gateway')
        ->expectsOutputToContain('Verify gateway API')
        ->expectsOutputToContain('Live Incus topology [dev-abc123] is ready.')
        ->expectsOutputToContain('WireGuard config')
        ->expectsOutputToContain('Endpoint = 192.168.1.150:51820')
        ->expectsOutputToContain('WireGuard interface: oe2eabc123')
        ->expectsOutputToContain('Local gateway: incus-dev-abc123')
        ->expectsOutputToContain('Gateway check: orbit node:list --json')
        ->expectsOutputToContain('Local gateway [incus-dev-abc123] is active.')
        ->expectsOutputToContain('Release: composer e2e:incus -- --stop --id=dev-abc123')
        ->assertSuccessful();
});

it('can create a live topology in manual mode without mutating the local host', function (): void {
    putenv('ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT=192.168.1.150:51820');

    incusDevTopologyCommandWith(fn (E2ETopologyKind $kind, array $roles): array => fakeIncusPreparedTopology());

    $log = new ArrayObject(['runs' => [], 'local_runs' => []]);
    incusLiveCommandWith($log);

    $output = new BufferedOutput;
    $exitCode = app(Kernel::class)->call('e2e:incus', [
        '--live' => true,
        '--manual' => true,
        '--json' => true,
    ], $output);

    $payload = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);
    $liveTopology = $payload['success']['live_topology'];

    expect($exitCode)->toBe(0)
        ->and($liveTopology['wireguard']['started'])->toBeFalse()
        ->and($liveTopology['wireguard']['gateway_added'])->toBeFalse()
        ->and($liveTopology['wireguard']['verified'])->toBeFalse()
        ->and($liveTopology['next_steps'])->toContain("Run `wg-quick up {$this->manifestDirectory}/oe2eabc123.conf`.")
        ->and($liveTopology['next_steps'])->toContain('Run `orbit gateway:add 10.6.0.2 --name=incus-dev-abc123`.')
        ->and($log['local_runs'])->toBe([]);
});

it('fails live local setup before mutation when wg quick tooling is unavailable', function (): void {
    putenv('ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT=192.168.1.150:51820');

    incusDevTopologyCommandWith(fn (E2ETopologyKind $kind, array $roles): array => fakeIncusPreparedTopology());

    $log = new ArrayObject(['runs' => [], 'local_runs' => []]);
    incusLiveCommandWith($log, recordingLiveIncusLocalMachine($log, toolsAvailable: false));

    $this->artisan('e2e:incus', [
        '--live' => true,
        '--json' => true,
    ])
        ->expectsOutputToContain('local_wireguard_unavailable')
        ->assertExitCode(1);

    expect($log['runs'])->toBe([])
        ->and($log['local_runs'])->toBe([]);
});

it('rejects live mode without a configured WireGuard endpoint', function (): void {
    $this->artisan('e2e:incus', [
        '--live' => true,
        '--json' => true,
    ])
        ->expectsOutputToContain('Set ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT')
        ->assertExitCode(1);
});

it('rejects dry-run live mode because no operator identity can be minted', function (): void {
    putenv('ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT=192.168.1.150:51820');

    $this->artisan('e2e:incus', [
        '--live' => true,
        '--dry-run' => true,
        '--json' => true,
    ])
        ->expectsOutputToContain('--live cannot be combined with --dry-run')
        ->assertExitCode(1);
});

it('syncs the current checkout to a retained Incus topology by id', function (): void {
    writeIncusRetainedManifest($this->manifestDirectory, 'dev-abc123');

    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result();
    });

    $output = new BufferedOutput;
    $exitCode = app(Kernel::class)->call('e2e:incus', [
        '--sync' => true,
        '--id' => 'dev-abc123',
        '--json' => true,
    ], $output);

    $payload = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);
    $sync = $payload['success']['source_sync'];
    $commandsOutput = implode("\n", $commands);

    expect($exitCode)->toBe(0)
        ->and($sync['id'])->toBe('dev-abc123')
        ->and($sync['kind'])->toBe('operator_gateway_app-dev')
        ->and($sync['provider'])->toBe('incus')
        ->and($sync['host'])->toBe('beast')
        ->and($sync['source_path'])->toContain('-incus-')
        ->and($sync['checkouts']['operator'])->toBe('/home/orbit/orbit-current')
        ->and($sync['runtime_checkouts']['operator'])->toBe('/home/orbit/orbit-current')
        ->and($sync['sync_command'])->toBe('composer e2e:incus -- --sync --id=dev-abc123')
        ->and($sync['release_command'])->toBe('composer e2e:incus -- --stop --id=dev-abc123')
        ->and($commandsOutput)
        ->toContain('rsync -az --delete')
        ->toContain("'beast:{$sync['source_path']}/'")
        ->toContain('incus exec')
        ->toContain('orbit-e2e-dev-abc123-operator')
        ->toContain('sudo -u')
        ->toContain('orbit')
        ->toContain('/home/orbit/orbit')
        ->toContain('/home/orbit/orbit-current')
        ->toContain('/home/orbit/.orbit-current-overlay/upper')
        ->toContain('/home/orbit/.orbit-current-overlay/work')
        ->toContain('mount -t overlay overlay')
        ->not->toContain('tar -C "${target}" -xf -')
        ->not->toContain('$sudo_prefix rm -rf "$target" "$upper" "$work"');
});

it('syncs source-mounted retained Incus checkouts into overlay runtime paths', function (): void {
    writeIncusRetainedManifest($this->manifestDirectory, 'dev-abc123', [
        'operator' => '/home/orbit/orbit',
        'gateway' => '/home/orbit/orbit',
        'dev' => '/home/orbit/orbit',
    ]);

    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result();
    });

    $output = new BufferedOutput;
    $exitCode = app(Kernel::class)->call('e2e:incus', [
        '--sync' => true,
        '--id' => 'dev-abc123',
        '--json' => true,
    ], $output);

    $payload = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);
    $sync = $payload['success']['source_sync'];
    $commandsOutput = implode("\n", $commands);

    expect($exitCode)->toBe(0)
        ->and($sync['runtime_checkouts'])->toBe([
            'operator' => '/home/orbit/orbit-run',
            'gateway' => '/home/orbit/orbit-run',
            'dev' => '/home/orbit/orbit-run',
        ])
        ->and($commandsOutput)
        ->toContain('/home/orbit/orbit')
        ->toContain('/home/orbit/orbit-run')
        ->toContain('/home/orbit/.orbit-run-overlay/upper')
        ->toContain('/home/orbit/.orbit-run-overlay/work')
        ->toContain('mount -t overlay overlay')
        ->toContain('orbit-e2e-dev-abc123-operator')
        ->toContain('orbit-e2e-dev-abc123-gateway')
        ->toContain('orbit-e2e-dev-abc123-dev')
        ->not->toContain('tar -C "${target}" -xf -')
        ->not->toContain('$sudo_prefix rm -rf "$target" "$upper" "$work"');
});

it('prints a human retained Incus sync summary', function (): void {
    writeIncusRetainedManifest($this->manifestDirectory, 'dev-abc123');

    Process::fake(fn () => Process::result());

    $this->artisan('e2e:incus', [
        '--sync' => true,
        '--id' => 'dev-abc123',
    ])
        ->expectsOutputToContain('Synced retained Incus topology [dev-abc123].')
        ->expectsOutputToContain('Host: beast')
        ->expectsOutputToContain('Source path:')
        ->expectsOutputToContain('Mounted checkouts:')
        ->expectsOutputToContain('- operator: /home/orbit/orbit-current')
        ->expectsOutputToContain('Release: composer e2e:incus -- --stop --id=dev-abc123')
        ->assertSuccessful();
});

it('requires an id when syncing a retained Incus topology', function (): void {
    $this->artisan('e2e:incus', [
        '--sync' => true,
        '--json' => true,
    ])
        ->expectsOutputToContain('A retained topology id is required for --sync.')
        ->assertExitCode(1);
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

it('stops a recorded live wg quick tunnel before releasing the topology', function (): void {
    writeIncusRetainedManifest($this->manifestDirectory, 'dev-abc123');

    $store = new E2EDevTopologyManifestStore($this->manifestDirectory);
    $manifest = $store->read('dev-abc123');

    expect($manifest)->not->toBeNull();

    $configPath = "{$this->manifestDirectory}/oe2eabc123.conf";

    $store->write([
        ...$manifest,
        'live' => [
            'wireguard' => [
                'interface' => 'oe2eabc123',
                'real_interface' => 'utun42',
                'config_path' => $configPath,
                'started' => true,
            ],
        ],
    ]);

    file_put_contents($configPath, '[Interface]');

    $log = new ArrayObject([
        'deleted' => [],
        'runs' => [],
        'local_runs' => [],
        'interfaces' => ['utun42'],
        'real' => ['oe2eabc123' => 'utun42'],
    ]);

    incusReleaseCommandWith($log);

    $command = app(E2EIncusCommand::class);
    $command->localMachineUsing(fn (): LiveIncusLocalMachine => recordingLiveIncusLocalMachine($log));
    app()->instance(E2EIncusCommand::class, $command);

    $this->artisan('e2e:incus', [
        '--stop' => true,
        '--id' => 'dev-abc123',
        '--json' => true,
    ])->assertSuccessful();

    expect($log['local_runs'])->toContain("wg-quick down {$configPath}")
        ->and($log['deleted'])->toHaveCount(1);
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
        ->expectsOutputToContain('Choose exactly one Incus topology action: --start, --stop, --live, or --sync.')
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
