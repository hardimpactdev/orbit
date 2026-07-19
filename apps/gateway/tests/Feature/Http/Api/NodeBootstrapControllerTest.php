<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeBootstrap;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\OperationRun;
use App\Models\WireGuardPeer;
use App\Services\Nodes\NodeBootstrapCompletionLock;
use App\Services\Nodes\NodeBootstrapCompletionResult;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Vpn\VpnDnsSwarmInstaller;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Http\JsonEnvelope;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->nodeBootstrapConfigRoot = sys_get_temp_dir().'/orbit-node-bootstrap-controller-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->nodeBootstrapConfigRoot.'/ca');
    File::put($this->nodeBootstrapConfigRoot.'/ca/root.key', 'fake-root-key');
    File::put(
        $this->nodeBootstrapConfigRoot.'/ca/root.crt',
        "-----BEGIN CERTIFICATE-----\nfake-root-cert\n-----END CERTIFICATE-----\n",
    );

    config()->set('orbit.paths.config_root', $this->nodeBootstrapConfigRoot);
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

afterEach(function (): void {
    if (isset($this->nodeBootstrapConfigRoot)) {
        File::deleteDirectory($this->nodeBootstrapConfigRoot);
    }
});

it('rejects a second analytics bootstrap before reserving the target node', function (): void {
    [, $caller] = nodeBootstrapGatewayAndCaller();

    $databaseNode = Node::factory()
        ->database()
        ->create(['name' => 'database-1']);

    foreach (['postgres', 'clickhouse'] as $service) {
        \App\Models\Process::factory()
            ->forOwner($databaseNode)
            ->create(['runtime_config' => ['service' => $service]]);
    }

    $existingAnalyticsNode = Node::factory()->create(['name' => 'analytics-1']);
    NodeRoleAssignment::factory()->for($existingAnalyticsNode)->create([
        'role' => 'analytics',
        'status' => NodeRoleStatus::Error,
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson('/api/nodes/bootstrap', [
            'name' => 'analytics-2',
            'roles' => ['analytics'],
            'host' => '192.0.2.30',
            'user' => 'root',
            'tld' => 'analytics-2',
            'platform' => 'ubuntu_24-04',
            'architecture' => 'amd64',
            'postgres_node' => 'database-1',
            'clickhouse_node' => 'database-1',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.message', "Role 'analytics' is already assigned to node 'analytics-1'.")
        ->assertJsonPath('error.meta.field', 'roles')
        ->assertJsonPath('error.meta.role', 'analytics');

    expect(Node::query()->where('name', 'analytics-2')->exists())
        ->toBeFalse()
        ->and(NodeBootstrap::query()->exists())
        ->toBeFalse();
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
            'platform' => 'ubuntu_24-04',
            'architecture' => 'amd64',
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
        ->toBeTrue()
        ->and(DB::table('activity_log')->where('event', 'node.created')->count())
        ->toBe(0);

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

        /** @var list<string> */
        public array $scripts = [];

        public function runInternal(
            Node $node,
            string $commandName,
            array $arguments = [],
            array $commandOptions = [],
            array $transportOptions = [],
        ): RemoteShellResult {
            expect($node->isAgentEligible())->toBeTrue();
            $this->calls++;

            if (is_string($transportOptions['input'] ?? null)) {
                /** @var array{script?: mixed} $input */
                $input = json_decode($transportOptions['input'], associative: true, flags: JSON_THROW_ON_ERROR);

                if (is_string($input['script'] ?? null)) {
                    $this->scripts[] = $input['script'];
                }
            }

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
            'platform' => 'ubuntu_24-04',
            'architecture' => 'amd64',
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
        ->toBe(5)
        ->and(implode("\n", $agentPush->scripts))
        ->toContain('/home/orbit/.ssh/authorized_keys')
        ->toContain('passwd -l root')
        ->toContain('rm -f /root/.ssh/authorized_keys')
        ->toContain('/etc/sysctl.d/60-orbit.conf')
        ->toContain('/etc/apt/apt.conf.d/50unattended-upgrades')
        ->toContain('ufw deny in')
        ->and(DB::table('activity_log')->where('event', 'node.created')->count())
        ->toBe(1);

    Http::assertSentCount(1);
    Process::assertRanTimes(
        fn ($process): bool => preg_match('/(?:^|\s)(?:ssh|scp)(?:\s|$)/', (string) $process->command) === 1,
        0,
    );
});

it('reconciles the s3 route and dns after the provisioned node becomes active', function (): void {
    [$gateway, $caller] = nodeBootstrapGatewayAndCaller();

    NodeRoleAssignment::factory()->create([
        'node_id' => $gateway->id,
        'role' => 'router',
        'status' => NodeRoleStatus::Active,
    ]);
    WireGuardPeer::query()->create([
        'node_id' => $gateway->id,
        'public_key' => 'gateway-peer-public-key',
        'private_key' => 'gateway-peer-private-key',
        'pre_shared_key' => 'gateway-peer-preshared-key',
        'allowed_ips' => '10.6.0.2/32',
    ]);

    $existingCaRoot = (string) config('orbit.paths.config_root').'/ca';
    $configRoot = storage_path('framework/testing/node-bootstrap-s3-dns-'.uniqid());
    File::copyDirectory($existingCaRoot, $configRoot.'/ca');
    config()->set('orbit.paths.config_root', $configRoot);
    app()->forgetInstance(\App\Services\Dns\DnsmasqReconciler::class);

    $roleAssignments = new class extends NodeRoleAssignmentService {
        public function __construct() {}

        public function addDuringCreation(Node $node, string $role, array $settings): NodeRoleAssignment
        {
            $assignment = $node->roleAssignments()->create([
                'role' => $role,
                'status' => NodeRoleStatus::Active,
                'settings' => $settings,
                'last_error' => null,
                'converged_at' => now(),
            ]);
            NodeTool::factory()->for($node)->create([
                'name' => 'seaweedfs',
                'config' => [
                    'backend_host' => "{$node->name}.s3.orbit",
                    'public_hosts' => [],
                ],
            ]);

            return $assignment;
        }

        public function retryDuringCreation(Node $node, string $role, array $settings): NodeRoleAssignment
        {
            return $node->roleAssignments()->where('role', $role)->firstOrFail();
        }
    };
    app()->instance(NodeRoleAssignmentService::class, $roleAssignments);
    app()->instance(RunsInternalCommands::class, nodeBootstrapSuccessfulAgentExecutor());

    $prepare = $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson('/api/nodes/bootstrap', [
            'name' => 'services1',
            'roles' => ['s3'],
            'host' => '192.0.2.31',
            'user' => 'root',
            'tld' => 'services1',
            'platform' => 'ubuntu_26-04',
            'architecture' => 'amd64',
            's3_data_path' => '/var/lib/orbit/s3',
        ])
        ->assertOk();

    Http::fake([
        'http://10.6.0.3:9477/v1/commands' => Http::response([], 405),
    ]);

    $bootstrapId = $prepare->json('success.data.bootstrap.id');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson("/api/nodes/bootstrap/{$bootstrapId}/complete")
        ->assertOk()
        ->assertJsonPath('success.data.node.status', 'active');

    expect(Node::query()->where('name', 'services1')->firstOrFail()->isActive())
        ->toBeTrue()
        ->and(File::get($configRoot.'/dnsmasq.d/10-node-records.conf'))
        ->toContain('address=/orbit.services1/10.6.0.3')
        ->and(File::get($configRoot.'/dnsmasq.d/20-proxy-records.conf'))
        ->toContain('address=/services1.s3.orbit/10.6.0.3')
        ->toContain('address=/orbit/10.6.0.2');

    File::deleteDirectory($configRoot);
});

it('serializes overlapping completion requests and records the winning transition once', function (): void {
    [$gateway, $caller] = nodeBootstrapGatewayAndCaller();

    WireGuardPeer::query()->create([
        'node_id' => $gateway->id,
        'public_key' => 'gateway-peer-public-key',
        'private_key' => 'gateway-peer-private-key',
        'pre_shared_key' => 'gateway-peer-preshared-key',
        'allowed_ips' => '10.6.0.2/32',
    ]);

    $completionLock = new class extends NodeBootstrapCompletionLock {
        private bool $held = false;

        public function synchronized(
            string $bootstrapId,
            \Closure $callback,
        ): NodeBootstrapCompletionResult {
            if ($this->held) {
                \Fiber::suspend('waiting-for-bootstrap-lock');

                return $this->synchronized($bootstrapId, $callback);
            }

            $this->held = true;

            try {
                return $callback();
            } finally {
                $this->held = false;
            }
        }
    };
    app()->instance(NodeBootstrapCompletionLock::class, $completionLock);

    $roleAssignments = nodeBootstrapRoleAssignments();
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
            $this->calls++;

            if ($this->calls === 1) {
                \Fiber::suspend('convergence-started');
            }

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
            'name' => 'database-overlap',
            'roles' => ['database'],
            'host' => '192.0.2.74',
            'user' => 'root',
            'tld' => 'database-overlap',
            'platform' => 'ubuntu_24-04',
            'architecture' => 'amd64',
        ])
        ->assertOk();

    Http::fake([
        'http://10.6.0.3:9477/v1/commands' => Http::response([], 405),
    ]);

    $bootstrapId = $prepare->json('success.data.bootstrap.id');
    $complete = fn () => $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson("/api/nodes/bootstrap/{$bootstrapId}/complete");

    $firstResponse = null;
    $first = new \Fiber(function () use ($complete, &$firstResponse): void {
        $firstResponse = $complete();
    });
    $secondResponse = null;
    $second = new \Fiber(function () use ($complete, &$secondResponse): void {
        $secondResponse = $complete();
    });

    expect($first->start())->toBe('convergence-started')->and($second->start())->toBe('waiting-for-bootstrap-lock');

    $first->resume();
    $second->resume();

    $firstResponse->assertOk()->assertJsonPath('success.data.node.status', 'active');
    $secondResponse->assertOk()->assertJsonPath('success.data.node.status', 'active');

    expect($first->isTerminated())
        ->toBeTrue()
        ->and($second->isTerminated())
        ->toBeTrue()
        ->and(NodeBootstrap::query()->findOrFail($bootstrapId)->status)
        ->toBe('completed')
        ->and(Node::query()->where('name', 'database-overlap')->firstOrFail()->isActive())
        ->toBeTrue()
        ->and($agentPush->calls)
        ->toBe(5)
        ->and(DB::table('activity_log')->where('event', 'node.created')->count())
        ->toBe(1);
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
            'platform' => 'ubuntu_24-04',
            'architecture' => 'amd64',
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
        ->call(
            'POST',
            "/api/nodes/bootstrap/{$bootstrapId}/complete",
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'text/event-stream',
                'REMOTE_ADDR' => $otherCaller->wireguard_address,
            ],
        )
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed');

    expect(NodeBootstrap::query()->findOrFail($bootstrapId)->status)
        ->toBe('pending')
        ->and(Node::query()->where('name', 'database-1')->firstOrFail()->isProvisioning())
        ->toBeTrue()
        ->and(OperationRun::query()->count())
        ->toBe(0)
        ->and(DB::table('activity_log')->where('event', 'node.created')->count())
        ->toBe(0);
});

it('requires client SSH preflight when no matching bootstrap is reserved yet', function (): void {
    [, $caller] = nodeBootstrapGatewayAndCaller();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson('/api/nodes/bootstrap/resume', [
            'name' => 'database-new',
            'roles' => ['database'],
            'host' => '192.0.2.70',
            'user' => 'root',
            'tld' => 'database-new',
        ])
        ->assertOk()
        ->assertJsonPath('success.data.preflight_required', true);
});

it('resumes Agent-ready and completed bootstraps without requiring SSH', function (string $status): void {
    [, $caller] = nodeBootstrapGatewayAndCaller();

    $node = Node::factory()->create([
        'name' => "database-{$status}",
        'host' => '192.0.2.71',
        'tld' => "database-{$status}",
        'wireguard_address' => '10.6.0.7',
        'platform' => 'ubuntu_24-04',
        'architecture' => 'amd64',
        'status' => $status === 'completed' ? 'active' : 'provisioning',
    ]);
    $bootstrap = NodeBootstrap::query()->create([
        'node_id' => $node->id,
        'initiating_node_id' => $caller->id,
        'request' => [
            'name' => "database-{$status}",
            '--host' => '192.0.2.71',
            '--tld' => "database-{$status}",
            '--user' => 'root',
            '--platform' => 'ubuntu_24-04',
            '--architecture' => 'amd64',
            '--roles' => 'database',
        ],
        'status' => $status,
    ]);

    Http::fake([
        'http://10.6.0.7:9477/v1/commands' => Http::response([], 405),
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson('/api/nodes/bootstrap/resume', [
            'name' => "database-{$status}",
            'roles' => ['database'],
            'host' => '192.0.2.71',
            'user' => 'root',
            'tld' => "database-{$status}",
        ])
        ->assertOk()
        ->assertJsonPath('success.data.preflight_required', false)
        ->assertJsonPath('success.data.bootstrap.id', $bootstrap->id)
        ->assertJsonPath('success.data.bootstrap.status', $status)
        ->assertJsonPath('success.data.bootstrap.ssh_required', false);

    if ($status === 'completed') {
        Http::assertNothingSent();
    } else {
        Http::assertSentCount(1);
    }
})->with(['pending', 'completed']);

it('does not expose another client bootstrap through the resume lookup', function (): void {
    [, $caller] = nodeBootstrapGatewayAndCaller();
    $otherCaller = Node::factory()->create([
        'name' => 'operator-resume',
        'wireguard_address' => '10.6.0.22',
    ]);
    $node = Node::factory()->create([
        'name' => 'database-private',
        'host' => '192.0.2.72',
        'tld' => 'database-private',
        'status' => 'provisioning',
    ]);
    NodeBootstrap::query()->create([
        'node_id' => $node->id,
        'initiating_node_id' => $otherCaller->id,
        'request' => [
            'name' => 'database-private',
            '--host' => '192.0.2.72',
            '--tld' => 'database-private',
            '--user' => 'root',
            '--platform' => 'ubuntu_24-04',
            '--architecture' => 'amd64',
            '--roles' => 'database',
        ],
        'status' => 'pending',
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson('/api/nodes/bootstrap/resume', [
            'name' => 'database-private',
            'roles' => ['database'],
            'host' => '192.0.2.72',
            'user' => 'root',
            'tld' => 'database-private',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'node.incompatible')
        ->assertJsonMissingPath('success.data.bootstrap.id');
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
        'platform' => 'ubuntu_24-04',
        'architecture' => 'amd64',
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

it('supports metrics bootstrap and persists the client-observed target platform', function (): void {
    [$gateway, $caller] = nodeBootstrapGatewayAndCaller();

    WireGuardPeer::query()->create([
        'node_id' => $gateway->id,
        'public_key' => 'gateway-peer-public-key',
        'private_key' => 'gateway-peer-private-key',
        'pre_shared_key' => 'gateway-peer-preshared-key',
        'allowed_ips' => '10.6.0.2/32',
    ]);

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson('/api/nodes/bootstrap', [
            'name' => 'metrics-1',
            'template' => 'metrics',
            'host' => '192.0.2.50',
            'user' => 'root',
            'tld' => 'metrics-test',
            'platform' => 'ubuntu_24-04',
            'architecture' => 'arm64',
        ])
        ->assertOk();

    $node = Node::query()->where('name', 'metrics-1')->firstOrFail();

    expect($node->platform)
        ->toBe('ubuntu_24-04')
        ->and($node->architecture)
        ->toBe('arm64')
        ->and($response->json('success.data.bootstrap.script'))
        ->toContain('orbit-linux-arm64');
});

it('rolls back an interrupted identity reservation so the same prepare can retry', function (): void {
    [$gateway, $caller] = nodeBootstrapGatewayAndCaller();

    WireGuardPeer::query()->create([
        'node_id' => $gateway->id,
        'public_key' => 'gateway-peer-public-key',
        'private_key' => 'gateway-peer-private-key',
        'pre_shared_key' => 'gateway-peer-preshared-key',
        'allowed_ips' => '10.6.0.2/32',
    ]);

    $creatingAttempts = 0;
    NodeBootstrap::creating(function () use (&$creatingAttempts): void {
        $creatingAttempts++;

        if ($creatingAttempts === 1) {
            throw new RuntimeException('simulated reservation interruption');
        }
    });

    $payload = [
        'name' => 'database-retry',
        'roles' => ['database'],
        'host' => '192.0.2.60',
        'user' => 'root',
        'tld' => 'database-retry',
        'platform' => 'ubuntu_24-04',
        'architecture' => 'amd64',
    ];

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson('/api/nodes/bootstrap', $payload)
        ->assertServerError();

    expect(Node::query()->where('name', 'database-retry')->exists())
        ->toBeFalse()
        ->and(
            WireGuardPeer::query()
                ->whereHas('node', fn ($query) => $query->where('name', 'database-retry'))
                ->exists(),
        )
        ->toBeFalse()
        ->and(NodeBootstrap::query()->count())
        ->toBe(0);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson('/api/nodes/bootstrap', $payload)
        ->assertOk()
        ->assertJsonPath('success.data.bootstrap.status', 'pending');
});

it('enforces a unique WireGuard address across node identities', function (): void {
    Node::factory()->create(['wireguard_address' => '10.6.0.40']);

    expect(fn () => Node::factory()->create(['wireguard_address' => '10.6.0.40']))
        ->toThrow(QueryException::class);
});

it('retries a WireGuard reservation that loses a unique-address race', function (): void {
    [$gateway, $caller] = nodeBootstrapGatewayAndCaller();

    WireGuardPeer::query()->create([
        'node_id' => $gateway->id,
        'public_key' => 'gateway-peer-public-key',
        'private_key' => 'gateway-peer-private-key',
        'pre_shared_key' => 'gateway-peer-preshared-key',
        'allowed_ips' => '10.6.0.2/32',
    ]);

    $collisions = 0;
    Node::creating(function (Node $node) use (&$collisions): void {
        if ($node->name !== 'database-collision' || $collisions > 0) {
            return;
        }

        $collisions++;
        Node::withoutEvents(
            fn () => Node::factory()->create(['wireguard_address' => $node->wireguard_address]),
        );
    });

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson('/api/nodes/bootstrap', [
            'name' => 'database-collision',
            'roles' => ['database'],
            'host' => '192.0.2.73',
            'user' => 'root',
            'tld' => 'database-collision',
            'platform' => 'ubuntu_24-04',
            'architecture' => 'amd64',
        ])
        ->assertOk()
        ->assertJsonPath('success.data.bootstrap.wireguard_address', '10.6.0.3');

    expect($collisions)
        ->toBe(1)
        ->and(Node::query()->where('wireguard_address', '10.6.0.3')->count())
        ->toBe(1)
        ->and(Node::query()->where('name', 'database-collision')->count())
        ->toBe(1);
});

it('keeps the node and bootstrap retryable when the terminal transition is interrupted', function (): void {
    [$gateway, $caller] = nodeBootstrapGatewayAndCaller();

    WireGuardPeer::query()->create([
        'node_id' => $gateway->id,
        'public_key' => 'gateway-peer-public-key',
        'private_key' => 'gateway-peer-private-key',
        'pre_shared_key' => 'gateway-peer-preshared-key',
        'allowed_ips' => '10.6.0.2/32',
    ]);

    app()->instance(NodeRoleAssignmentService::class, nodeBootstrapRoleAssignments());
    app()->instance(RunsInternalCommands::class, nodeBootstrapSuccessfulAgentExecutor());

    $prepare = $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson('/api/nodes/bootstrap', [
            'name' => 'database-transition',
            'roles' => ['database'],
            'host' => '192.0.2.61',
            'user' => 'root',
            'tld' => 'database-transition',
            'platform' => 'ubuntu_24-04',
            'architecture' => 'amd64',
        ])
        ->assertOk();

    Http::fake([
        'http://10.6.0.3:9477/v1/commands' => Http::response([], 405),
    ]);

    $terminalAttempts = 0;
    DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$terminalAttempts): void {
        if (
            ! str_contains($query, 'update "node_bootstraps"')
            || ! in_array('completed', $bindings, strict: true)
        ) {
            return;
        }

        $terminalAttempts++;

        if ($terminalAttempts === 1) {
            throw new RuntimeException('simulated terminal transition interruption');
        }
    });

    $bootstrapId = $prepare->json('success.data.bootstrap.id');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson("/api/nodes/bootstrap/{$bootstrapId}/complete")
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'node.provisioning_incomplete');

    expect(NodeBootstrap::query()->findOrFail($bootstrapId)->status)
        ->toBe('pending')
        ->and(Node::query()->where('name', 'database-transition')->firstOrFail()->isProvisioning())
        ->toBeTrue();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson("/api/nodes/bootstrap/{$bootstrapId}/complete")
        ->assertOk()
        ->assertJsonPath('success.data.node.status', 'active');

    expect(NodeBootstrap::query()->findOrFail($bootstrapId)->status)
        ->toBe('completed')
        ->and(Node::query()->where('name', 'database-transition')->firstOrFail()->isActive())
        ->toBeTrue();
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

function nodeBootstrapRoleAssignments(): NodeRoleAssignmentService
{
    return new class extends NodeRoleAssignmentService {
        public function __construct() {}

        public function addDuringCreation(Node $node, string $role, array $settings): NodeRoleAssignment
        {
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
            return $node->roleAssignments()->where('role', $role)->firstOrFail();
        }
    };
}

function nodeBootstrapSuccessfulAgentExecutor(): RunsInternalCommands
{
    return new class implements RunsInternalCommands {
        public function runInternal(
            Node $node,
            string $commandName,
            array $arguments = [],
            array $commandOptions = [],
            array $transportOptions = [],
        ): RemoteShellResult {
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
            'linux-arm64' => [
                'url' => 'https://artifacts.orbit.test/orbit-linux-arm64',
                'sha256' => str_repeat('c', 64),
            ],
        ],
        'agent_artifacts' => [
            'linux-amd64' => [
                'url' => 'https://artifacts.orbit.test/orbit-agent-linux-x64',
                'sha256' => str_repeat('d', 64),
            ],
            'linux-arm64' => [
                'url' => 'https://artifacts.orbit.test/orbit-agent-linux-arm64',
                'sha256' => str_repeat('f', 64),
            ],
        ],
        'role_images' => [
            'orbit-caddy' => 'ghcr.io/hardimpactdev/orbit-caddy@sha256:'.str_repeat('e', 64),
        ],
    ];
}

it('rejects an unsafe S3 data path before reserving a node bootstrap', function (): void {
    [, $caller] = nodeBootstrapGatewayAndCaller();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
        ->postJson('/api/nodes/bootstrap', [
            'name' => 'storage-unsafe',
            'roles' => ['s3'],
            'host' => '192.0.2.31',
            'user' => 'root',
            'tld' => 'storage-unsafe',
            'platform' => 'ubuntu_24-04',
            'architecture' => 'amd64',
            's3_data_path' => '/',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.field', 's3_data_path');

    expect(Node::query()->where('name', 'storage-unsafe')->exists())
        ->toBeFalse()
        ->and(NodeBootstrap::query()->exists())
        ->toBeFalse();
});
