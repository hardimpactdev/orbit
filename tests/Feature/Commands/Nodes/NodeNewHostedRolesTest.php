<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleStatus;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Http\Gateway\Requests\Nodes\CreateNodeRequest;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\WireGuardPeer;
use App\Services\OrbitHostInstaller;
use App\Services\OrbitHostInstallResult;
use App\Services\Platform\PlatformDetector;
use App\Services\Trust\TrustStoreInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => true]);

    $this->fakeInstaller = new class extends OrbitHostInstaller
    {
        public int $calls = 0;

        public function install(string $host, string $sshUser, string $runtimeUser = 'orbit'): OrbitHostInstallResult
        {
            $this->calls++;

            return new OrbitHostInstallResult(successful: true);
        }
    };

    app()->instance(OrbitHostInstaller::class, $this->fakeInstaller);

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
        'role' => 'gateway',
        'platform' => 'ubuntu',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'gateway_endpoint' => '10.6.0.2',
        'user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
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
                        'role' => 'control',
                        'status' => 'active',
                        'addresses' => ['wireguard' => '10.6.0.3'],
                    ],
                    'gateway' => [
                        'name' => 'gateway-1',
                        'role' => 'gateway',
                        'status' => 'active',
                        'addresses' => ['wireguard' => '10.6.0.2'],
                    ],
                ],
            ],
        ]),
    ]);

    Process::fake(function ($process) {
        $command = (string) $process->command;

        if ($command === 'ssh-keygen -y -f ~/.ssh/id_ed25519') {
            return Process::result(output: "ssh-ed25519 AAAATEST gateway\n");
        }

        if (str_contains($command, 'authorized_keys')) {
            return Process::result();
        }

        if ($command === 'wg genkey') {
            static $privateKeys = ['gateway-private-key', 'control-private-key'];

            return Process::result(output: array_shift($privateKeys)."\n");
        }

        if ($command === 'wg pubkey') {
            static $publicKeys = ['gateway-public-key', 'control-public-key'];

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

        return Process::result();
    });
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    File::deleteDirectory(storage_path('app/orbit/gateway-ca'));
    File::deleteDirectory(storage_path('app/orbit/node-development-dns.d'));
});

it('creates a joined client identity with no hosted roles by default', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'client-1',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Node::query()->where('name', 'client-1')->exists())->toBeTrue()
        ->and(NodeRoleAssignment::query()->count())->toBe(0);
});

it('creates an app-development hosted role with tld settings', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'dev-1',
        '--role' => ['app-development'],
        '--host' => '192.0.2.20',
        '--tld' => 'test',
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'dev-1')->first();

    expect($exitCode)->toBe(0)
        ->and($node)->not->toBeNull()
        ->and($node->roleAssignments)->toHaveCount(1)
        ->and($node->roleAssignments->first()?->role)->toBe('app-development')
        ->and($node->roleAssignments->first()?->status)->toBe(NodeRoleStatus::Active->value)
        ->and($node->roleAssignments->first()?->settings)->toBe(['tld' => 'test']);
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
        '--role' => ['app-development'],
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

it('adopts a compatible existing app node for canonical app-development', function (): void {
    $node = Node::factory()->create([
        'name' => 'dev-adopt-1',
        'role' => 'app',
        'environment' => 'development',
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

        if ($command === 'docker exec orbit-dns kill -HUP 1') {
            return Process::result();
        }

        return Process::result();
    });
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('node:new', [
        'name' => 'dev-adopt-1',
        '--role' => ['app-development'],
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
        'role' => 'app',
        'environment' => 'development',
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
    expect($node->fresh()->roleAssignments->first()?->role)->toBe('app-development');
    expect($node->fresh()->roleAssignments->first()?->status)->toBe(NodeRoleStatus::Active->value);

    Process::assertRanTimes(fn ($process): bool => str_contains($process->command, 'ssh '), 0);
});

it('creates compatible app-production and database hosted roles', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'web-1',
        '--role' => ['app-production', 'database'],
        '--host' => '192.0.2.21',
        '--json' => true,
    ]);

    $roles = NodeRoleAssignment::query()
        ->whereHas('node', fn ($query) => $query->where('name', 'web-1'))
        ->orderBy('role')
        ->get();

    expect($exitCode)->toBe(0)
        ->and($roles->pluck('role')->all())->toBe(['app-production', 'database'])
        ->and($roles->pluck('status')->unique()->all())->toBe([NodeRoleStatus::Active->value]);
});

it('adopts a compatible existing app node for canonical app-production plus database', function (): void {
    $node = Node::factory()->create([
        'name' => 'web-adopt-1',
        'role' => 'app',
        'environment' => 'production',
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
        '--role' => ['app-production', 'database'],
        '--host' => '192.0.2.31',
        '--user' => 'provisioner',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
    $roles = $node->fresh()->roleAssignments->sortBy('role')->values();

    expect($exitCode)->toBe(0);
    expect($payload['success']['data']['result']['action'])->toBe('adopted');
    expect($payload['success']['data']['node'])->toMatchArray([
        'name' => 'web-adopt-1',
        'role' => 'app',
        'environment' => 'production',
        'tld' => null,
        'addresses' => [
            'wireguard' => '10.6.0.11',
            'gateway_endpoint' => '10.6.0.2',
        ],
        'status' => 'active',
    ]);
    expect($this->fakeInstaller->calls)->toBe(0);
    expect($node->fresh()->status)->toBe('active');
    expect($node->fresh()->wireguard_address)->toBe('10.6.0.11');
    expect($roles->pluck('role')->all())->toBe(['app-production', 'database']);
    expect($roles->pluck('status')->unique()->all())->toBe([NodeRoleStatus::Active->value]);

    Process::assertRanTimes(fn ($process): bool => str_contains($process->command, 'ssh '), 0);
});

it('creates a database hosted role without requiring host input', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'db-1',
        '--role' => ['database'],
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
        '--role' => ['database'],
        '--host' => '192.0.2.20',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error'])->toMatchArray([
            'code' => 'validation_failed',
            'message' => 'Only app-development, app-production, and gateway use host provisioning.',
            'meta' => ['field' => 'host'],
        ])
        ->and(Node::query()->where('name', 'db-with-host')->exists())->toBeFalse()
        ->and($this->fakeInstaller->calls)->toBe(0);
});

it('rejects conflicting hosted roles before side effects', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'bad',
        '--role' => ['app-development', 'app-production'],
        '--host' => '192.0.2.22',
        '--tld' => 'test',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Node::query()->where('name', 'bad')->exists())->toBeFalse()
        ->and(NodeRoleAssignment::query()->count())->toBe(0);
});

it('rejects environment for canonical hosted-role input', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'canonical-env',
        '--role' => ['database'],
        '--environment' => 'production',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error'])->toMatchArray([
            'code' => 'validation_failed',
            'message' => 'Environment is only supported for legacy app role mapping.',
            'meta' => ['field' => 'environment'],
        ])
        ->and(Node::query()->where('name', 'canonical-env')->exists())->toBeFalse();
});

it('forwards canonical hosted app roles without legacy environment metadata', function (): void {
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
                        'role' => 'app',
                        'environment' => 'development',
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
        '--role' => ['app-development'],
        '--host' => '192.0.2.40',
        '--tld' => 'test',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0);

    $mock->assertSent(fn (CreateNodeRequest $request): bool => $request->body()->all() === [
        'name' => 'canonical-dev-1',
        'role' => 'app-development',
        'roles' => ['app-development'],
        'host' => '192.0.2.40',
        'environment' => null,
        'tld' => 'test',
        'user' => 'root',
    ]);
});

it('maps the legacy app role to app-development', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'legacy-dev-1',
        '--role' => ['app'],
        '--environment' => 'development',
        '--host' => '192.0.2.23',
        '--tld' => 'test',
        '--json' => true,
    ]);

    $assignment = NodeRoleAssignment::query()
        ->whereHas('node', fn ($query) => $query->where('name', 'legacy-dev-1'))
        ->first();

    expect($exitCode)->toBe(0)
        ->and($assignment)->not->toBeNull()
        ->and($assignment->role)->toBe('app-development')
        ->and($assignment->settings)->toBe(['tld' => 'test']);
});

it('creates exactly one gateway assignment during first gateway bootstrap', function (): void {
    DB::table('nodes')->delete();
    config(['orbit.is_gateway' => false]);

    $exitCode = Artisan::call('node:new', [
        'name' => 'gateway-1',
        '--role' => ['gateway'],
        '--host' => '192.0.2.10',
        '--control-name' => 'control-1',
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
