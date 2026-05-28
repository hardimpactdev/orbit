<?php

declare(strict_types=1);

use App\Data\Security\PinnedHostKey;
use App\Enums\Nodes\NodeRoleStatus;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\CreateNodeRequest;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Models\WireGuardPeer;
use App\Services\OrbitHostInstaller;
use App\Services\OrbitHostInstallResult;
use App\Services\Platform\PlatformDetector;
use App\Services\Security\SshHostKeyPinner;
use App\Services\Tools\ToolInstaller;
use App\Services\Trust\TrustStoreInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'orbit.is_gateway' => true,
        'orbit.operation_token_secret' => 'agent-node-new-test-secret',
    ]);

    $this->tempStorage = sys_get_temp_dir().'/orbit-agent-node-new-test-'.uniqid();
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

    app()->instance(SshHostKeyPinner::class, new class
    {
        public function pin(string $host, ?string $expectedFingerprint = null): PinnedHostKey
        {
            return new PinnedHostKey(
                host: $host,
                type: 'ssh-ed25519',
                publicKey: 'AAAAC3NzaC1lZDI1NTE5AAAAIMockEd25519KeyForOrbitTests',
                fingerprint: $expectedFingerprint ?? 'SHA256:agent-node-test',
                pinMode: $expectedFingerprint === null ? 'tofu' : 'verified',
            );
        }
    });

    $this->fakeToolInstaller = new class
    {
        public array $installCalls = [];

        public function install(string $tool, ?string $node = null, ?string $app = null, string $expectedState = 'installed', array $config = []): array
        {
            $this->installCalls[] = ['tool' => $tool, 'node' => $node, 'expectedState' => $expectedState];

            return ['name' => $tool, 'node' => $node, 'state' => $expectedState];
        }
    };

    app()->bind(ToolInstaller::class, fn () => $this->fakeToolInstaller);

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

    Process::fake(function ($process) {
        $command = (string) $process->command;

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
                'ca_cert' => testAgentGatewayCaCertificate(),
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
            return Process::result(output: json_encode(['ok' => true], JSON_THROW_ON_ERROR)."\n");
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

it('creates an agent node with default self-grant', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--self-grant' => 'default',
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'agent-1')->first();
    $selfGrant = NodeAccess::query()
        ->where('consumer_node_id', $node->id)
        ->where('serving_node_id', $node->id)
        ->first();

    expect($exitCode)->toBe(0)
        ->and($node)->not->toBeNull()
        ->and($node->roleAssignments)->toHaveCount(1)
        ->and($node->roleAssignments->first()?->role)->toBe('agent')
        ->and($node->roleAssignments->first()?->status)->toBe(NodeRoleStatus::Active->value)
        ->and($node->roleAssignments->first()?->settings)->toBe(['tld' => 'agent'])
        ->and($selfGrant)->not->toBeNull()
        ->and($selfGrant->permissions)->toBe([
            'doctor:verify',
            'node:read',
            'tool:read',
            'tool:restart',
            'tool:update:agent-tools',
        ])
        ->and($selfGrant->custom_permissions)->toBe([]);

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, "'orbit'@'192.0.2.10'")
        && str_contains((string) $process->command, '99-orbit-hardening.conf')
        && str_contains((string) $process->command, 'PermitRootLogin no')
        && str_contains((string) $process->command, 'PasswordAuthentication no')
        && str_contains((string) $process->command, 'AllowUsers ${RUNTIME_USER}')
        && str_contains((string) $process->command, 'rm -f /root/.ssh/authorized_keys'));
});

it('creates an agent node with default self-grant when omitted', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'agent-1')->first();
    $selfGrant = NodeAccess::query()
        ->where('consumer_node_id', $node->id)
        ->where('serving_node_id', $node->id)
        ->first();

    expect($exitCode)->toBe(0)
        ->and($node)->not->toBeNull()
        ->and($selfGrant)->not->toBeNull()
        ->and($selfGrant->permissions)->toBe([
            'doctor:verify',
            'node:read',
            'tool:read',
            'tool:restart',
            'tool:update:agent-tools',
        ])
        ->and($selfGrant->custom_permissions)->toBe([]);
});

it('creates an agent node with custom self-grant permissions', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--self-grant' => 'custom',
        '--self-grant-permissions' => 'node:read,tool:read',
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'agent-1')->first();
    $selfGrant = NodeAccess::query()
        ->where('consumer_node_id', $node->id)
        ->where('serving_node_id', $node->id)
        ->first();

    expect($exitCode)->toBe(0)
        ->and($selfGrant)->not->toBeNull()
        ->and($selfGrant->permissions)->toBe(['node:read', 'tool:read'])
        ->and($selfGrant->custom_permissions)->toBe(['node:read', 'tool:read']);
});

it('expands grant-to=all to all current eligible nodes', function (): void {
    $appNode = Node::factory()->create([
        'name' => 'app-1',

        'status' => 'active',
        'wireguard_address' => '10.6.0.5',
    ]);

    $dbNode = Node::factory()->create([
        'name' => 'db-1',

        'status' => 'active',
        'wireguard_address' => '10.6.0.6',
    ]);

    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--grant-to' => ['all'],
        '--grant-to-preset' => 'operator',
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'agent-1')->first();

    $appGrant = NodeAccess::query()
        ->where('consumer_node_id', $node->id)
        ->where('serving_node_id', $appNode->id)
        ->first();

    $dbGrant = NodeAccess::query()
        ->where('consumer_node_id', $node->id)
        ->where('serving_node_id', $dbNode->id)
        ->first();

    expect($exitCode)->toBe(0)
        ->and($appGrant)->not->toBeNull()
        ->and($dbGrant)->not->toBeNull()
        ->and($appGrant->permissions)->toContain('node:read')
        ->and($dbGrant->permissions)->toContain('node:read');
});

it('expands grant-from=all to all current eligible nodes', function (): void {
    $controlNode = Node::factory()->create([
        'name' => 'control-1',

        'status' => 'active',
        'wireguard_address' => '10.6.0.5',
    ]);

    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--grant-from' => ['all'],
        '--grant-from-preset' => 'operator',
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'agent-1')->first();

    $controlGrant = NodeAccess::query()
        ->where('consumer_node_id', $controlNode->id)
        ->where('serving_node_id', $node->id)
        ->first();

    expect($exitCode)->toBe(0)
        ->and($controlGrant)->not->toBeNull()
        ->and($controlGrant->permissions)->toContain('node:read');
});

it('creates an agent node without a default tool', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'agent-1')->first();

    expect($exitCode)->toBe(0)
        ->and($node)->not->toBeNull();
});

it('selects a single agent tool without warning', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--agent-tool' => ['openclaw'],
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['node']['name'] ?? null)->toBe('agent-1')
        ->and($payload['success']['meta']['warnings'] ?? null)->toBeNull();
});

it('emits multiple-agent-tools warning when selecting multiple agent tools', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--agent-tool' => ['openclaw', 'hermes'],
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['meta']['warnings'] ?? null)->not->toBeNull()
        ->and($payload['success']['meta']['warnings'][0]['code'] ?? null)->toBe('tool.multiple_agent_tools_running');
});

it('asks human callers to confirm multiple agent tools before side effects', function (): void {
    $this->artisan('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--user' => 'root',
        '--agent-tool' => ['openclaw', 'hermes'],
    ])
        ->expectsConfirmation('Continue installing all tools?', 'no')
        ->assertExitCode(1);

    expect(Node::query()->where('name', 'agent-1')->exists())->toBeFalse()
        ->and($this->fakeInstaller->calls)->toBe(0)
        ->and($this->fakeToolInstaller->installCalls)->toHaveCount(0);
});

it('continues after human callers confirm multiple agent tools', function (): void {
    $this->artisan('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--user' => 'root',
        '--agent-tool' => ['openclaw', 'hermes'],
    ])
        ->expectsConfirmation('Continue installing all tools?', 'yes')
        ->assertExitCode(0);

    expect(Node::query()->where('name', 'agent-1')->exists())->toBeTrue()
        ->and($this->fakeToolInstaller->installCalls)->toHaveCount(2);
});

it('supports repeatable agent-tool selection', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--agent-tool' => ['openclaw', 'hermes'],
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['meta']['warnings'][0]['code'] ?? null)->toBe('tool.multiple_agent_tools_running');
});

it('does not offer gateway-admin by default in grant setup', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--grant-to' => ['gateway-1'],
        '--grant-to-preset' => 'gateway-admin',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed');
});

it('creates agent node with tld defaulting to agent', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'agent-1')->first();

    expect($exitCode)->toBe(0)
        ->and($node)->not->toBeNull()
        ->and($node->roleAssignments->first()?->settings)->toBe(['tld' => 'agent']);
});

it('rejects invalid agent-tool names', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--agent-tool' => ['not-a-real-tool'],
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed');
});

it('rejects non-agent tools via agent-tool option', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--agent-tool' => ['docker'],
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed');
});

it('does not create accidental self-grant via grant-to=all', function (): void {
    Node::factory()->create([
        'name' => 'app-1',

        'status' => 'active',
        'wireguard_address' => '10.6.0.5',
    ]);

    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--grant-to' => ['all'],
        '--grant-to-preset' => 'operator',
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'agent-1')->first();

    $selfGrants = NodeAccess::query()
        ->where('consumer_node_id', $node->id)
        ->where('serving_node_id', $node->id)
        ->get();

    expect($exitCode)->toBe(0)
        ->and($selfGrants)->toHaveCount(1)
        ->and($selfGrants->first()->permissions)->toBe([
            'doctor:verify',
            'node:read',
            'tool:read',
            'tool:restart',
            'tool:update:agent-tools',
        ]);
});

it('does not create accidental self-grant via grant-from=all', function (): void {
    Node::factory()->create([
        'name' => 'control-1',

        'status' => 'active',
        'wireguard_address' => '10.6.0.5',
    ]);

    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--grant-from' => ['all'],
        '--grant-from-preset' => 'operator',
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'agent-1')->first();

    $selfGrants = NodeAccess::query()
        ->where('consumer_node_id', $node->id)
        ->where('serving_node_id', $node->id)
        ->get();

    expect($exitCode)->toBe(0)
        ->and($selfGrants)->toHaveCount(1)
        ->and($selfGrants->first()->permissions)->toBe([
            'doctor:verify',
            'node:read',
            'tool:read',
            'tool:restart',
            'tool:update:agent-tools',
        ]);
});

it('fails explicitly for missing named grant-to target', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--grant-to' => ['missing-node'],
        '--grant-to-preset' => 'operator',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('node.not_found')
        ->and($payload['error']['meta']['node'])->toBe('missing-node');
});

it('fails explicitly for missing named grant-from target', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--grant-from' => ['missing-node'],
        '--grant-from-preset' => 'operator',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('node.not_found')
        ->and($payload['error']['meta']['node'])->toBe('missing-node');
});

it('hands off selected agent tools to tool installer', function (): void {
    Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--agent-tool' => ['openclaw'],
        '--json' => true,
    ]);

    expect($this->fakeToolInstaller->installCalls)->toHaveCount(1)
        ->and($this->fakeToolInstaller->installCalls[0]['tool'])->toBe('openclaw')
        ->and($this->fakeToolInstaller->installCalls[0]['node'])->toBe('agent-1')
        ->and($this->fakeToolInstaller->installCalls[0]['expectedState'])->toBe('installed');
});

it('hands off multiple agent tools to tool installer', function (): void {
    Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--agent-tool' => ['openclaw', 'hermes'],
        '--json' => true,
    ]);

    expect($this->fakeToolInstaller->installCalls)->toHaveCount(2)
        ->and($this->fakeToolInstaller->installCalls[0]['tool'])->toBe('openclaw')
        ->and($this->fakeToolInstaller->installCalls[1]['tool'])->toBe('hermes');
});

it('does not install tools when agent-tool is omitted', function (): void {
    Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--json' => true,
    ]);

    expect($this->fakeToolInstaller->installCalls)->toHaveCount(0);
});

it('forwards agent setup inputs from operator node to gateway', function (): void {
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
                        'name' => 'agent-1',
                        'status' => 'active',
                    ],
                    'provisioning' => [
                        'transport' => 'ssh',
                        'host' => '192.0.2.10',
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
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--self-grant' => 'default',
        '--grant-to' => ['all'],
        '--grant-to-preset' => 'operator',
        '--grant-from' => ['control-1'],
        '--grant-from-preset' => 'read-only',
        '--agent-tool' => ['openclaw'],
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0);

    $mock->assertSent(fn (CreateNodeRequest $request): bool => $request->body()->all() === [
        'name' => 'agent-1',
        'roles' => ['agent'],
        'host' => '192.0.2.10',
        'tld' => 'agent',
        'user' => 'root',
        'self_grant' => 'default',
        'grant_to' => ['all'],
        'grant_to_preset' => 'operator',
        'grant_from' => ['control-1'],
        'grant_from_preset' => 'read-only',
        'agent_tools' => ['openclaw'],
    ]);
});

it('leaves no node when invalid agent tool is provided', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--agent-tool' => ['not-a-real-tool'],
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'agent-1')->first();

    expect($exitCode)->toBe(1)
        ->and($node)->toBeNull()
        ->and($this->fakeInstaller->calls)->toBe(0)
        ->and($this->fakeToolInstaller->installCalls)->toHaveCount(0);
});

it('leaves no node when non-agent tool is provided via agent-tool', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--agent-tool' => ['docker'],
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'agent-1')->first();

    expect($exitCode)->toBe(1)
        ->and($node)->toBeNull()
        ->and($this->fakeInstaller->calls)->toBe(0)
        ->and($this->fakeToolInstaller->installCalls)->toHaveCount(0);
});

it('leaves no node when grant target is missing', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--grant-to' => ['missing-node'],
        '--grant-to-preset' => 'operator',
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'agent-1')->first();

    expect($exitCode)->toBe(1)
        ->and($node)->toBeNull()
        ->and($this->fakeInstaller->calls)->toBe(0);
});

it('leaves no node when gateway-admin preset is requested', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--grant-to' => ['gateway-1'],
        '--grant-to-preset' => 'gateway-admin',
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'agent-1')->first();

    expect($exitCode)->toBe(1)
        ->and($node)->toBeNull()
        ->and($this->fakeInstaller->calls)->toBe(0);
});

it('fails before side effects when agent-tool is used without agent role', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'app-1',
        '--role' => ['app'],
        '--host' => '192.0.2.10',
        '--environment' => 'production',
        '--agent-tool' => ['openclaw'],
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'app-1')->first();
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($node)->toBeNull()
        ->and($this->fakeInstaller->calls)->toBe(0)
        ->and($this->fakeToolInstaller->installCalls)->toHaveCount(0);
});

it('fails orphan --grant-to-preset without --grant-to before side effects', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--grant-to-preset' => 'operator',
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'agent-1')->first();
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($node)->toBeNull()
        ->and($this->fakeInstaller->calls)->toBe(0);
});

it('fails orphan --grant-from-permissions without --grant-from before side effects', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--grant-from-permissions' => 'node:read',
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'agent-1')->first();
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($node)->toBeNull()
        ->and($this->fakeInstaller->calls)->toBe(0);
});

it('fails --self-grant-permissions without --self-grant=custom before side effects', function (): void {
    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--self-grant-permissions' => 'node:read',
        '--json' => true,
    ]);

    $node = Node::query()->where('name', 'agent-1')->first();
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($node)->toBeNull()
        ->and($this->fakeInstaller->calls)->toBe(0);
});

it('preserves gateway success.meta.warnings through control-node forwarding', function (): void {
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
                        'name' => 'agent-1',
                        'status' => 'active',
                    ],
                ],
                'meta' => [
                    'warnings' => [
                        [
                            'code' => 'tool.multiple_agent_tools_running',
                            'tools' => ['openclaw', 'hermes'],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);
    app()->instance(GatewayConnector::class, $connector);

    $exitCode = Artisan::call('node:new', [
        'name' => 'agent-1',
        '--role' => ['agent'],
        '--host' => '192.0.2.10',
        '--tld' => 'agent',
        '--agent-tool' => ['openclaw', 'hermes'],
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['meta']['warnings'] ?? null)->not->toBeNull()
        ->and($payload['success']['meta']['warnings'][0]['code'] ?? null)->toBe('tool.multiple_agent_tools_running')
        ->and($payload['success']['meta']['warnings'][0]['tools'] ?? null)->toBe(['openclaw', 'hermes']);
});

function testAgentGatewayCaCertificate(): string
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
