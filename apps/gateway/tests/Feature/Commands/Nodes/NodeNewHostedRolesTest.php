<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Data\Security\PinnedHostKey;
use App\Enums\Nodes\NodeRoleStatus;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Http\Gateway\Requests\Nodes\CreateNodeRequest;
use App\Models\FirewallRule;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\WireGuardPeer;
use App\Services\OrbitHostInstaller;
use App\Services\OrbitHostInstallResult;
use App\Services\Platform\PlatformDetector;
use App\Services\Security\SshHostKeyPinner;
use App\Services\Trust\TrustStoreInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'orbit.is_gateway' => true,
        'orbit.operation_token_secret' => 'node-new-hosted-roles-test-secret',
    ]);

    $this->tempStorage = sys_get_temp_dir().'/orbit-node-new-hosted-roles-test-'.uniqid();
    app()->useStoragePath($this->tempStorage);

    $this->fakeInstaller = new class extends OrbitHostInstaller
    {
        public int $calls = 0;

        public function install(string $host, string $sshUser, string $runtimeUser = 'orbit', bool $asGateway = false): OrbitHostInstallResult
        {
            $this->calls++;

            return new OrbitHostInstallResult(successful: true);
        }
    };

    app()->instance(OrbitHostInstaller::class, $this->fakeInstaller);

    $this->fakeHostKeyPinner = new class
    {
        /** @var list<array{host: string, expected: ?string}> */
        public array $calls = [];

        public function pin(string $host, ?string $expectedFingerprint = null): PinnedHostKey
        {
            $this->calls[] = ['host' => $host, 'expected' => $expectedFingerprint];

            return new PinnedHostKey(
                host: $host,
                type: 'ssh-ed25519',
                publicKey: 'AAAAC3NzaC1lZDI1NTE5AAAAIMockEd25519KeyForOrbitTests',
                fingerprint: $expectedFingerprint ?? 'SHA256:hosted-role-test',
                pinMode: $expectedFingerprint === null ? 'tofu' : 'verified',
            );
        }
    };

    app()->instance(SshHostKeyPinner::class, $this->fakeHostKeyPinner);

    app()->instance(PlatformDetector::class, new class extends PlatformDetector
    {
        public function detectLocal(): string
        {
            return 'macos_15-4';
        }
    });

    app()->instance(TrustStoreInstaller::class, new class implements TrustStoreInstaller
    {
        public function isCaTrusted(string $rootCaPath, string $label): bool
        {
            return true;
        }

        public function trustCa(string $rootCaPath, string $label, ?Closure $log = null): void {}
    });

    $gateway = Node::factory()->create([
        'name' => 'gateway-1',

        'platform' => 'ubuntu',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'gateway_endpoint' => '10.6.0.2',
        'user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'active',
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGatewayPinnedHostKeyForRuntimeModeTests',
        'host_key_fingerprint' => 'SHA256:gateway-runtime-test',
        'host_key_pin_mode' => 'verified',
        'host_key_pinned_at' => now(),
    ]);

    NodeRoleAssignment::factory()->for($gateway)->create([
        'role' => 'gateway',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->for($gateway)->create([
        'role' => 'vpn',
        'status' => 'active',
    ]);

    WireGuardPeer::query()->create([
        'node_id' => $gateway->id,
        'public_key' => 'gateway-public-key',
        'private_key' => 'gateway-private-key',
        'allowed_ips' => '10.6.0.2/32',
    ]);

    MockClient::global([
        ShowGatewayIdentityRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'self' => [
                        'name' => 'control-1',
                        'status' => 'active',
                        'addresses' => ['wireguard' => '10.6.0.3'],
                    ],
                    'gateway' => [
                        'name' => 'gateway-1',
                        'status' => 'active',
                        'addresses' => ['wireguard' => '10.6.0.2'],
                    ],
                ],
            ],
        ]),
    ]);

    $this->processCommands = [];

    Process::fake(function ($process) {
        $command = (string) $process->command;
        $this->processCommands[] = $command;

        if ($command === 'ssh-keygen -y -f ~/.ssh/id_ed25519') {
            return Process::result(output: "ssh-ed25519 AAAATEST gateway\n");
        }

        if (str_contains($command, 'authorized_keys')) {
            return Process::result();
        }

        if ($command === 'wg genkey') {
            static $privateKeys = ['node-private-key-1', 'node-private-key-2', 'node-private-key-3'];

            return Process::result(output: array_shift($privateKeys)."\n");
        }

        if ($command === 'wg pubkey') {
            static $publicKeys = ['node-public-key-1', 'node-public-key-2', 'node-public-key-3'];

            return Process::result(output: array_shift($publicKeys)."\n");
        }

        if (str_contains($command, 'orbit:internal:bootstrap-gateway-local')) {
            return Process::result(output: json_encode([
                'ca_cert' => testGatewayCaCertificate(),
                'wireguard_server_public_key' => 'gateway-public-key',
            ], JSON_THROW_ON_ERROR)."\n");
        }

        if (str_contains($command, 'orbit:internal:detect-platform')) {
            return Process::result(output: "ubuntu_24-04\n");
        }

        if (str_contains($command, 'wg show wg0 public-key')) {
            return Process::result(output: "wg-easy-public-key\n");
        }

        if (str_contains($command, 'internal:wg-easy:state')) {
            return Process::result(output: json_encode(['success' => ['data' => []]], JSON_THROW_ON_ERROR)."\n");
        }

        return Process::result();
    });
});

afterEach(function (): void {
    MockClient::destroyGlobal();

    if (isset($this->tempStorage) && File::isDirectory($this->tempStorage)) {
        File::deleteDirectory($this->tempStorage);
    }
});

it('creates a joined client identity with no hosted roles by default', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'client-1',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Node::query()->where('name', 'client-1')->exists())->toBeTrue()
        ->and(NodeRoleAssignment::query()->whereRelation('node', 'name', 'client-1')->count())->toBe(0);
});

it('creates an app-dev hosted role with tld settings', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'dev-1',
        '--roles' => 'app-dev',
        '--host' => '192.0.2.20',
        '--tld' => 'test',
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'dev-1')->first();

    expect($exitCode)->toBe(0)
        ->and($node)->not->toBeNull()
        ->and($node->roleAssignments)->toHaveCount(1)
        ->and($node->roleAssignments->first()?->role)->toBe('app-dev')
        ->and($node->roleAssignments->first()?->status)->toBe(NodeRoleStatus::Active->value)
        ->and($node->roleAssignments->first()?->settings)->toBe(['tld' => 'test'])
        ->and(WireGuardPeer::query()->where('node_id', $node->id)->exists())->toBeTrue()
        ->and(FirewallRule::query()->where('node_id', $node->id)->where('owner', 'node-security')->count())->toBe(3);

    $commands = implode("\n", $this->processCommands);

    expect($commands)->toContain('99-orbit-hardening.conf')
        ->toContain('internal:wg-easy:state')
        ->toContain('delete-peer')
        ->toContain('upsert-peer')
        ->toContain('wg set wg0 peer')
        ->toContain('/etc/wireguard/wg-orbit.conf')
        ->toContain('ping -c 1 -W 2')
        ->toContain('PermitRootLogin no')
        ->toContain('AllowUsers')
        ->not->toContain('clients_table')
        ->not->toContain('sqlite3')
        ->not->toContain('sudo sqlite3');
});

it('creates app-development template roles with canonical stored values', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'dev-template-1',
        '--template' => 'app-development',
        '--host' => '192.0.2.25',
        '--tld' => 'template',
        '--json' => true,
    ]);

    $node = Node::query()
        ->with('roleAssignments')
        ->where('name', 'dev-template-1')
        ->first();

    expect($exitCode)->toBe(0)
        ->and($node)->not->toBeNull()
        ->and($node->roleAssignments->pluck('role')->sort()->values()->all())->toBe(['app-dev', 'database'])
        ->and($node->roleAssignments->firstWhere('role', 'app-dev')?->settings)->toBe(['tld' => 'template'])
        ->and($node->roleAssignments->firstWhere('role', 'database')?->settings)->toBe([]);
});

it('checks provisioned node WireGuard reachability from the gateway host when running in orbit-runtime', function (): void {
    $previousSourcePath = getenv('ORBIT_SOURCE_PATH');

    putenv('ORBIT_SOURCE_PATH=/opt/orbit');

    $shell = new class implements RemoteShell
    {
        /** @var list<array{node: string, script: string, options: array<string, mixed>}> */
        public array $runs = [];

        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            $this->runs[] = [
                'node' => (string) $node->name,
                'script' => $script,
                'options' => $options,
            ];

            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }
    };

    app()->instance(RemoteShell::class, $shell);

    try {
        $exitCode = Artisan::call('node:new', [
            'name' => 'dev-runtime-1',
            '--roles' => 'app-dev',
            '--host' => '192.0.2.24',
            '--tld' => 'runtime',
            '--json' => true,
        ]);
    } finally {
        is_string($previousSourcePath)
            ? putenv("ORBIT_SOURCE_PATH={$previousSourcePath}")
            : putenv('ORBIT_SOURCE_PATH');
    }

    expect($exitCode, Artisan::output())->toBe(0);

    $node = Node::query()->where('name', 'dev-runtime-1')->firstOrFail();

    expect(collect($shell->runs)->contains(fn (array $run): bool => $run['node'] === 'gateway-1'
        && $run['script'] === sprintf('ping -c 1 -W 2 %s', escapeshellarg((string) $node->wireguard_address))
        && ($run['options']['timeout'] ?? null) === 5))->toBeTrue();
});

it('honors the prepared topology WireGuard address reservation during E2E provisioning', function (): void {
    $previousE2E = getenv('ORBIT_E2E');
    $previousReservedAddress = getenv('ORBIT_E2E_NODE_WIREGUARD_ADDRESS');

    putenv('ORBIT_E2E=1');
    putenv('ORBIT_E2E_NODE_WIREGUARD_ADDRESS=10.6.0.44');

    try {
        $exitCode = Artisan::call('node:new', [
            'name' => 'dev-reserved-1',
            '--roles' => 'app-dev',
            '--host' => '192.0.2.44',
            '--tld' => 'reserved',
            '--json' => true,
        ]);
    } finally {
        is_string($previousE2E)
            ? putenv("ORBIT_E2E={$previousE2E}")
            : putenv('ORBIT_E2E');
        is_string($previousReservedAddress)
            ? putenv("ORBIT_E2E_NODE_WIREGUARD_ADDRESS={$previousReservedAddress}")
            : putenv('ORBIT_E2E_NODE_WIREGUARD_ADDRESS');
    }

    $node = Node::query()->where('name', 'dev-reserved-1')->first();
    $peer = WireGuardPeer::query()->where('node_id', $node?->id)->first();

    expect($exitCode)->toBe(0)
        ->and($node)->not->toBeNull()
        ->and($node->wireguard_address)->toBe('10.6.0.44')
        ->and($peer?->allowed_ips)->toBe('10.6.0.44/32');
});

it('pins the host key before provisioning and persists the canonical steady-state user', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'dev-pinned-1',
        '--roles' => 'app-dev',
        '--host' => '192.0.2.50',
        '--tld' => 'pinned',
        '--user' => 'ubuntu',
        '--host-key-fingerprint' => 'SHA256:expected',
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'dev-pinned-1')->firstOrFail();

    expect($exitCode)->toBe(0)
        ->and($this->fakeHostKeyPinner->calls)->toBe([
            ['host' => '192.0.2.50', 'expected' => 'SHA256:expected'],
        ])
        ->and($node->user)->toBe('orbit')
        ->and($node->status)->toBe(Node::STATUS_ACTIVE)
        ->and($node->host_key_type)->toBe('ssh-ed25519')
        ->and($node->host_key_fingerprint)->toBe('SHA256:expected')
        ->and($node->host_key_pin_mode)->toBe('verified');

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, '-o StrictHostKeyChecking=yes')
        && str_contains((string) $process->command, "'ubuntu'@'192.0.2.50'"));
});

it('rolls back the provisional node row when host provisioning fails', function (): void {
    $this->fakeInstaller = new class extends OrbitHostInstaller
    {
        public function install(string $host, string $sshUser, string $runtimeUser = 'orbit', bool $asGateway = false): OrbitHostInstallResult
        {
            return new OrbitHostInstallResult(
                successful: false,
                errorOutput: 'installer failed',
            );
        }
    };

    app()->instance(OrbitHostInstaller::class, $this->fakeInstaller);

    $exitCode = Artisan::call('node:new', [
        'name' => 'rollback-1',
        '--roles' => 'ingress',
        '--host' => '192.0.2.51',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Node::query()->where('name', 'rollback-1')->exists())->toBeFalse();

    $entry = Activity::query()
        ->where('event', 'node.provisioning.failed')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties->get('node'))->toBe('rollback-1')
        ->and($entry->properties->get('reason'))->toBe('host_installer_failed');
});

it('rejects existing gateway development dns mappings before provisioning side effects', function (): void {
    $mappingPath = storage_path('app/orbit/node-development-dns.d/test.conf');
    File::ensureDirectoryExists(dirname($mappingPath));
    File::put($mappingPath, implode("\n", [
        '# orbit-managed=node-development-dns',
        '# node=other-dev',
        '# bind-scope=orbit_network',
        'address=/test/10.6.0.99',
        '',
    ]));

    $exitCode = Artisan::call('node:new', [
        'name' => 'dev-conflict',
        '--roles' => 'app-dev',
        '--host' => '192.0.2.20',
        '--tld' => 'test',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error'])->toMatchArray([
            'code' => 'node.incompatible',
            'message' => "Development TLD 'test' is already mapped to another gateway development DNS target.",
        ])
        ->and($payload['error']['meta']['field'])->toBe('tld')
        ->and($payload['error']['meta']['value'])->toBe('test')
        ->and($payload['error']['meta']['actual_target'])->toBe('10.6.0.99')
        ->and(Node::query()->where('name', 'dev-conflict')->exists())->toBeFalse()
        ->and($this->fakeInstaller->calls)->toBe(0)
        ->and(File::get($mappingPath))->toContain('address=/test/10.6.0.99');
});

it('adopts a compatible existing app node for canonical app-dev', function (): void {
    $node = Node::factory()->create([
        'name' => 'dev-adopt-1',

        'tld' => 'test',
        'platform' => 'ubuntu_24-04',
        'host' => '192.0.2.30',
        'wireguard_address' => '10.6.0.8',
        'gateway_endpoint' => '10.6.0.2',
        'user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'decommissioned',
    ]);

    WireGuardPeer::query()->create([
        'node_id' => $node->id,
        'public_key' => 'app-public-key',
        'private_key' => 'app-private-key',
        'allowed_ips' => '10.6.0.8/32',
    ]);

    Process::fake(function ($process) {
        $command = (string) $process->command;

        if ($command === 'sudo wg show wg-orbit allowed-ips') {
            return Process::result(output: "app-public-key\t10.6.0.9/32\n");
        }

        if ($command === 'docker restart orbit-dns') {
            return Process::result();
        }

        return Process::result();
    });
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('node:new', [
        'name' => 'dev-adopt-1',
        '--roles' => 'app-dev',
        '--host' => '192.0.2.30',
        '--tld' => 'test',
        '--user' => 'provisioner',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0);
    expect($payload['success']['data']['result']['action'])->toBe('adopted');
    expect($payload['success']['data']['node'])->toMatchArray([
        'name' => 'dev-adopt-1',
        'tld' => 'test',
        'addresses' => [
            'wireguard' => '10.6.0.9',
            'gateway_endpoint' => '10.6.0.2',
        ],
        'status' => 'active',
    ]);
    expect($this->fakeInstaller->calls)->toBe(0);
    expect($node->fresh()->status)->toBe('active');
    expect($node->fresh()->wireguard_address)->toBe('10.6.0.9');
    expect($node->fresh()->roleAssignments)->toHaveCount(1);
    expect($node->fresh()->roleAssignments->first()?->role)->toBe('app-dev');
    expect($node->fresh()->roleAssignments->first()?->status)->toBe(NodeRoleStatus::Active->value);

    Process::assertRanTimes(fn ($process): bool => str_contains($process->command, 'ssh '), 0);
});

it('rejects app-prod and database hosted roles before provisioning side effects', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'web-1',
        '--roles' => 'app-prod,database',
        '--host' => '192.0.2.21',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error'])->toMatchArray([
            'code' => 'validation_failed',
            'message' => 'Hosted roles app-prod and database cannot be combined.',
            'meta' => [
                'field' => 'roles',
                'conflicts' => ['app-prod', 'database'],
            ],
        ])
        ->and(Node::query()->where('name', 'web-1')->exists())->toBeFalse()
        ->and($this->fakeInstaller->calls)->toBe(0);
});

it('rejects adopting app-prod plus database before touching the existing node', function (): void {
    $node = Node::factory()->create([
        'name' => 'web-adopt-1',

        'tld' => null,
        'platform' => 'ubuntu_24-04',
        'host' => '192.0.2.31',
        'wireguard_address' => '10.6.0.10',
        'gateway_endpoint' => '10.6.0.2',
        'user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'decommissioned',
    ]);

    WireGuardPeer::query()->create([
        'node_id' => $node->id,
        'public_key' => 'web-public-key',
        'private_key' => 'web-private-key',
        'allowed_ips' => '10.6.0.10/32',
    ]);

    Process::fake(function ($process) {
        $command = (string) $process->command;

        if ($command === 'sudo wg show wg-orbit allowed-ips') {
            return Process::result(output: "web-public-key\t10.6.0.11/32\n");
        }

        return Process::result();
    });
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('node:new', [
        'name' => 'web-adopt-1',
        '--roles' => 'app-prod,database',
        '--host' => '192.0.2.31',
        '--user' => 'provisioner',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1);
    expect($payload['error'])->toMatchArray([
        'code' => 'validation_failed',
        'message' => 'Hosted roles app-prod and database cannot be combined.',
        'meta' => [
            'field' => 'roles',
            'conflicts' => ['app-prod', 'database'],
        ],
    ]);
    expect($this->fakeInstaller->calls)->toBe(0);
    expect($node->fresh()->status)->toBe('decommissioned');
    expect($node->fresh()->wireguard_address)->toBe('10.6.0.10');
    expect($node->fresh()->roleAssignments)->toHaveCount(0);

    Process::assertRanTimes(fn ($process): bool => str_contains($process->command, 'ssh '), 0);
});

it('creates a database hosted role without requiring host input', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'db-1',
        '--roles' => 'database',
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'db-1')->first();

    expect($exitCode)->toBe(0)
        ->and($node)->not->toBeNull()
        ->and($node->host)->toBe('')
        ->and($node->user)->toBe('orbit')
        ->and($this->fakeInstaller->calls)->toBe(0)
        ->and($node->roleAssignments)->toHaveCount(1)
        ->and($node->roleAssignments->first()?->role)->toBe('database')
        ->and($node->roleAssignments->first()?->status)->toBe(NodeRoleStatus::Active->value);
});

it('rejects host input for database-only hosted roles before side effects', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'db-with-host',
        '--roles' => 'database',
        '--host' => '192.0.2.20',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error'])->toMatchArray([
            'code' => 'validation_failed',
            'message' => 'Only app-dev, app-prod, ingress, agent, and gateway use host provisioning.',
            'meta' => ['field' => 'host'],
        ])
        ->and(Node::query()->where('name', 'db-with-host')->exists())->toBeFalse()
        ->and($this->fakeInstaller->calls)->toBe(0);
});

it('rejects conflicting hosted roles before side effects', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'bad',
        '--roles' => 'app-dev,app-prod',
        '--host' => '192.0.2.22',
        '--tld' => 'test',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Node::query()->where('name', 'bad')->exists())->toBeFalse()
        ->and(NodeRoleAssignment::query()->whereRelation('node', 'name', 'bad')->count())->toBe(0);
});

it('rejects retired app role input before side effects', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'retired-app-role',
        '--roles' => 'app',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error'])->toMatchArray([
            'code' => 'validation_failed',
            'message' => 'Node roles must be one or more of app-dev, app-prod, database, agent, ingress, websocket, or s3.',
            'meta' => ['field' => 'roles'],
        ])
        ->and(Node::query()->where('name', 'retired-app-role')->exists())->toBeFalse();
});

it('forwards canonical hosted app roles without environment metadata', function (): void {
    config(['orbit.is_gateway' => false]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();

    $mock = new MockClient([
        CreateNodeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'result' => ['action' => 'created'],
                    'node' => [
                        'name' => 'canonical-dev-1',
                        'tld' => 'test',
                        'status' => 'active',
                    ],
                    'provisioning' => [
                        'transport' => 'ssh',
                        'host' => '192.0.2.40',
                        'status' => 'complete',
                    ],
                    'next_steps' => [],
                ],
            ],
        ]),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);
    app()->instance(GatewayConnector::class, $connector);

    $exitCode = Artisan::call('node:new', [
        'name' => 'canonical-dev-1',
        '--roles' => 'app-dev',
        '--host' => '192.0.2.40',
        '--tld' => 'test',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0);

    $mock->assertSent(fn (CreateNodeRequest $request): bool => $request->body()->all() === [
        'name' => 'canonical-dev-1',
        'roles' => ['app-dev'],
        'host' => '192.0.2.40',
        'tld' => 'test',
        'user' => 'root',
    ]);
});

it('forwards private app production ingress names without requiring local registry sync', function (): void {
    config(['orbit.is_gateway' => false]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();

    $mock = new MockClient([
        CreateNodeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'result' => ['action' => 'created'],
                    'node' => [
                        'name' => 'web-1',
                        'status' => 'active',
                    ],
                    'provisioning' => [
                        'transport' => 'ssh',
                        'host' => '192.0.2.41',
                        'status' => 'complete',
                    ],
                    'next_steps' => [],
                ],
            ],
        ]),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);
    app()->instance(GatewayConnector::class, $connector);

    $exitCode = Artisan::call('node:new', [
        'name' => 'web-1',
        '--roles' => 'app-prod',
        '--host' => '192.0.2.41',
        '--ingress' => 'edge-1',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0);

    $mock->assertSent(fn (CreateNodeRequest $request): bool => $request->body()->all() === [
        'name' => 'web-1',
        'roles' => ['app-prod'],
        'host' => '192.0.2.41',
        'tld' => null,
        'user' => 'root',
        'ingress_node' => 'edge-1',
    ]);
});

it('rejects retired long app role input before side effects', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'long-app-role',
        '--roles' => 'app-development',
        '--host' => '192.0.2.23',
        '--tld' => 'test',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error'])->toMatchArray([
            'code' => 'validation_failed',
            'message' => 'Node roles must be one or more of app-dev, app-prod, database, agent, ingress, websocket, or s3.',
            'meta' => ['field' => 'roles'],
        ])
        ->and(Node::query()->where('name', 'long-app-role')->exists())->toBeFalse();
});

it('creates exactly one gateway assignment during first gateway bootstrap', function (): void {
    DB::table('nodes')->delete();
    config(['orbit.is_gateway' => false]);

    $exitCode = Artisan::call('node:new', [
        'name' => 'gateway-1',
        '--template' => 'gateway',
        '--host' => '192.0.2.10',
        '--operator-name' => 'operator-1',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(NodeRoleAssignment::query()->where('role', 'gateway')->count())->toBe(1);
});

function testGatewayCaCertificate(): string
{
    static $certificate = null;

    if (is_string($certificate)) {
        return $certificate;
    }

    $keyPath = tempnam(sys_get_temp_dir(), 'orbit-key-');
    $certPath = tempnam(sys_get_temp_dir(), 'orbit-cert-');

    shell_exec(sprintf('openssl genrsa -out %s 2048 2>/dev/null', escapeshellarg($keyPath)));
    shell_exec(sprintf(
        'openssl req -x509 -new -nodes -key %s -sha256 -days 1 -out %s -subj %s 2>/dev/null',
        escapeshellarg($keyPath),
        escapeshellarg($certPath),
        escapeshellarg('/CN=Orbit Test CA/O=Orbit'),
    ));

    $certificate = trim((string) file_get_contents($certPath));

    @unlink($keyPath);
    @unlink($certPath);

    return $certificate;
}
