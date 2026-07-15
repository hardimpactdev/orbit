<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeBootstrap;
use App\Models\NodeRoleAssignment;
use App\Models\OperationRun;
use App\Models\WireGuardPeer;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Vpn\VpnDnsSwarmInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Http\JsonEnvelope;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('orbit.updates.manifest_snapshot', nodeBootstrapControllerReleaseManifest());
    config()->set('orbit.node_bootstrap.readiness_attempts', 1);
    config()->set('orbit.node_bootstrap.readiness_delay_milliseconds', 0);

    $vpn = new class extends VpnDnsSwarmInstaller {
        /** @var list<array<string, mixed>> */
        public array $peers = [];

        public function __construct() {}

        public function configurePeers(array $peers): void
        {
            $this->peers = $peers;
        }

        public function publicKey(): string
        {
            return 'gateway-public-key';
        }
    };
    app()->instance(VpnDnsSwarmInstaller::class, $vpn);

    Process::fake(function ($process) {
        $command = (string) $process->command;

        if ($command === 'wg genkey') {
            return Process::result(output: "node-private-key\n");
        }

        if ($command === 'wg pubkey') {
            return Process::result(output: "node-public-key\n");
        }

        if (str_contains($command, 'internal:wg-easy:state')) {
            return Process::result(output: json_encode([
                'success' => ['data' => [], 'meta' => []],
            ], JSON_THROW_ON_ERROR)
                ."\n");
        }

        return Process::result();
    });
    Process::preventStrayProcesses();
});

it('keeps bootstrap pending when the WireGuard-bound Agent is not ready', function (): void {
    [$gateway, $caller] = nodeBootstrapGatewayAndCaller();

    WireGuardPeer::query()->create([
        'node_id' => $gateway->id,
        'public_key' => 'gateway-peer-public-key',
        'private_key' => 'gateway-peer-private-key',
        'pre_shared_key' => 'gateway-peer-preshared-key',
        'allowed_ips' => '10.6.0.2/32',
    ]);

    $prepare = $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson('/api/nodes/bootstrap', [
            'name' => 'agent-1',
            'roles' => ['agent'],
            'host' => '192.0.2.20',
            'user' => 'root',
            'tld' => 'agent-test',
        ])
        ->assertOk();

    Http::fake([
        'http://10.6.0.3:9477/v1/commands' => Http::response([], 503),
    ]);

    $bootstrapId = $prepare->json('success.data.bootstrap.id');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson("/api/nodes/bootstrap/{$bootstrapId}/complete")
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'node.provisioning_incomplete')
        ->assertJsonPath('error.meta.step', 'agent_readiness');

    expect(NodeBootstrap::query()->findOrFail($bootstrapId)->status)
        ->toBe('pending')
        ->and(Node::query()->where('name', 'agent-1')->firstOrFail()->isProvisioning())
        ->toBeTrue();

    Process::assertRanTimes(
        fn ($process): bool => preg_match('/(?:^|\s)(?:ssh|scp)(?:\s|$)/', (string) $process->command) === 1,
        0,
    );
});

it('completes through the WireGuard Agent once and then returns the active result idempotently', function (): void {
    [$gateway, $caller] = nodeBootstrapGatewayAndCaller();

    WireGuardPeer::query()->create([
        'node_id' => $gateway->id,
        'public_key' => 'gateway-peer-public-key',
        'private_key' => 'gateway-peer-private-key',
        'pre_shared_key' => 'gateway-peer-preshared-key',
        'allowed_ips' => '10.6.0.2/32',
    ]);

    $roleAssignments = new class extends NodeRoleAssignmentService {
        public int $convergences = 0;

        public function __construct() {}

        public function addDuringCreation(Node $node, string $role, array $settings): NodeRoleAssignment
        {
            $this->convergences++;

            return $node->roleAssignments()->create([
                'role' => $role,
                'status' => NodeRoleStatus::Active,
                'settings' => $settings,
                'last_error' => null,
                'converged_at' => now(),
            ]);
        }

        public function retryDuringCreation(Node $node, string $role, array $settings): NodeRoleAssignment
        {
            $this->convergences++;

            return $node->roleAssignments()->where('role', $role)->firstOrFail();
        }
    };
    app()->instance(NodeRoleAssignmentService::class, $roleAssignments);

    $agentPush = new class implements RunsInternalCommands {
        public int $calls = 0;

        public function runInternal(
            Node $node,
            string $commandName,
            array $arguments = [],
            array $commandOptions = [],
            array $transportOptions = [],
        ): RemoteShellResult {
            expect($node->isAgentEligible())->toBeTrue();
            $this->calls++;

            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode(JsonEnvelope::success([
                    'exit_code' => 0,
                    'stdout' => '',
                    'stderr' => '',
                    'duration_ms' => 1,
                ]), JSON_THROW_ON_ERROR)
                    ."\n",
                stderr: '',
                durationMs: 1,
            );
        }
    };
    app()->instance(RunsInternalCommands::class, $agentPush);

    $prepare = $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson('/api/nodes/bootstrap', [
            'name' => 'database-1',
            'roles' => ['database'],
            'host' => '192.0.2.20',
            'user' => 'root',
            'tld' => 'database-test',
        ])
        ->assertOk();

    Http::fake([
        'http://10.6.0.3:9477/v1/commands' => Http::response([], 405),
    ]);

    $bootstrapId = $prepare->json('success.data.bootstrap.id');

    $response = $this->call(
        'POST',
        "/api/nodes/bootstrap/{$bootstrapId}/complete",
        [],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => $caller->wireguard_address,
        ],
    );

    $response->assertOk();

    $content = $response->streamedContent();
    $operationRun = OperationRun::query()->where('operation_type', 'node:new')->firstOrFail();

    expect($content)
        ->toContain('event: complete')
        ->and($content)
        ->toContain($operationRun->id)
        ->and($operationRun->status->value)
        ->toBe('succeeded')
        ->and($operationRun->result['success']['data']['node']['status'])
        ->toBe('active')
        ->and(json_encode($operationRun->result, JSON_THROW_ON_ERROR))
        ->not->toContain('script');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson("/api/nodes/bootstrap/{$bootstrapId}/complete")
        ->assertOk()
        ->assertJsonPath('success.data.node.status', 'active');

    expect(NodeBootstrap::query()->findOrFail($bootstrapId)->status)
        ->toBe('completed')
        ->and(Node::query()->where('name', 'database-1')->firstOrFail()->isActive())
        ->toBeTrue()
        ->and($roleAssignments->convergences)
        ->toBe(1)
        ->and($agentPush->calls)
        ->toBe(2);

    Http::assertSentCount(1);
    Process::assertRanTimes(
        fn ($process): bool => preg_match('/(?:^|\s)(?:ssh|scp)(?:\s|$)/', (string) $process->command) === 1,
        0,
    );
});

it('allows only the initiating client to complete a pending bootstrap', function (): void {
    [$gateway, $caller] = nodeBootstrapGatewayAndCaller();

    WireGuardPeer::query()->create([
        'node_id' => $gateway->id,
        'public_key' => 'gateway-peer-public-key',
        'private_key' => 'gateway-peer-private-key',
        'pre_shared_key' => 'gateway-peer-preshared-key',
        'allowed_ips' => '10.6.0.2/32',
    ]);

    $prepare = $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson('/api/nodes/bootstrap', [
            'name' => 'database-1',
            'roles' => ['database'],
            'host' => '192.0.2.20',
            'user' => 'root',
            'tld' => 'database-test',
        ])
        ->assertOk();

    $otherCaller = Node::factory()->create([
        'name' => 'operator-2',
        'wireguard_address' => '10.6.0.21',
    ]);
    DB::table('node_access')->insert([
        'consumer_node_id' => $otherCaller->id,
        'serving_node_id' => $gateway->id,
        'permissions' => json_encode(['node:new'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $bootstrapId = $prepare->json('success.data.bootstrap.id');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $otherCaller->wireguard_address])
        ->postJson("/api/nodes/bootstrap/{$bootstrapId}/complete")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed');

    expect(NodeBootstrap::query()->findOrFail($bootstrapId)->status)
        ->toBe('pending')
        ->and(Node::query()->where('name', 'database-1')->firstOrFail()->isProvisioning())
        ->toBeTrue();
});

it('reserves one retryable bootstrap without gateway target SSH', function (): void {
    [$gateway, $caller] = nodeBootstrapGatewayAndCaller();

    WireGuardPeer::query()->create([
        'node_id' => $gateway->id,
        'public_key' => 'gateway-peer-public-key',
        'private_key' => 'gateway-peer-private-key',
        'pre_shared_key' => 'gateway-peer-preshared-key',
        'allowed_ips' => '10.6.0.2/32',
    ]);

    $payload = [
        'name' => 'app-dev-1',
        'roles' => ['app-dev'],
        'host' => '192.0.2.20',
        'user' => 'root',
        'tld' => 'test',
    ];

    $first = $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson('/api/nodes/bootstrap', $payload)
        ->assertOk()
        ->assertJsonPath('success.data.bootstrap.status', 'pending')
        ->assertJsonPath('success.data.bootstrap.host', '192.0.2.20')
        ->assertJsonPath('success.data.bootstrap.user', 'root')
        ->assertJsonPath('success.data.bootstrap.wireguard_address', '10.6.0.3');

    $second = $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson('/api/nodes/bootstrap', $payload)
        ->assertOk();

    expect($first->json('success.data.bootstrap.id'))
        ->toBe($second->json('success.data.bootstrap.id'))
        ->and($first->json('success.data.bootstrap.script'))
        ->toBeString()
        ->not
        ->toBe('')
        ->and(Node::query()->where('name', 'app-dev-1')->count())
        ->toBe(1)
        ->and(NodeBootstrap::query()->count())
        ->toBe(1)
        ->and(
            WireGuardPeer::query()
                ->whereHas('node', fn ($query) => $query->where('name', 'app-dev-1'))
                ->count(),
        )
        ->toBe(1);

    Process::assertRanTimes(
        fn ($process): bool => preg_match('/(?:^|\s)(?:ssh|scp)(?:\s|$)/', (string) $process->command) === 1,
        0,
    );
});

/**
 * @return array{Node, Node}
 */
function nodeBootstrapGatewayAndCaller(): array
{
    $gateway = Node::factory()->create([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.2',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $gateway->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $gateway->id,
        'role' => 'vpn',
        'status' => 'active',
        'settings' => ['public_endpoint' => 'gateway.example.com'],
    ]);

    $caller = Node::factory()->create([
        'name' => 'operator-1',
        'wireguard_address' => '10.6.0.20',
    ]);

    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $gateway->id,
        'permissions' => json_encode(['node:new'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$gateway, $caller];
}

/**
 * @return array<string, mixed>
 */
function nodeBootstrapControllerReleaseManifest(): array
{
    return [
        'schema_version' => 1,
        'version' => '1.2.3',
        'source' => 'github-release',
        'images' => [
            'gateway' => 'ghcr.io/hardimpactdev/orbit-gateway@sha256:'.str_repeat('a', 64),
        ],
        'cli_artifacts' => [
            'linux-amd64' => [
                'url' => 'https://artifacts.orbit.test/orbit-linux-x64',
                'sha256' => str_repeat('b', 64),
            ],
        ],
        'agent_artifacts' => [
            'linux-amd64' => [
                'url' => 'https://artifacts.orbit.test/orbit-agent-linux-x64',
                'sha256' => str_repeat('d', 64),
            ],
        ],
        'role_images' => [
            'orbit-caddy' => 'ghcr.io/hardimpactdev/orbit-caddy@sha256:'.str_repeat('e', 64),
        ],
    ];
}
