<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Data\Security\PinnedHostKey;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\WireGuardPeer;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Operations\ProvisioningAgentInstaller;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Security\SshHostKeyPinner;
use App\Services\Vpn\VpnDnsSwarmInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Fakes\NodeStoreProvisioningAgentInstaller;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    bindDevelopmentDnsMappingTestDoubles('node-store-controller-dns');
    app()->instance(ProvisioningAgentInstaller::class, new NodeStoreProvisioningAgentInstaller);

    app()->instance(SshHostKeyPinner::class, new class {
        public function pin(string $host, ?string $expectedFingerprint = null): PinnedHostKey
        {
            return new PinnedHostKey(
                host: $host,
                type: 'ssh-ed25519',
                publicKey: 'AAAAC3NzaC1lZDI1NTE5AAAAIMockEd25519KeyForOrbitTests',
                fingerprint: $expectedFingerprint ?? 'SHA256:node-store-test',
                pinMode: $expectedFingerprint === null ? 'tofu' : 'verified',
            );
        }
    });
});

afterEach(function (): void {
    File::deleteDirectory(app(DevelopmentDnsMappingEnactor::class)->configDir());
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiStoreNodeRow(array $overrides = []): array
{
    $row = array_merge([
        'name' => 'gateway-1',
        'platform' => 'unknown',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'gateway_endpoint' => null,
        'user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    if (! array_key_exists('tld', $overrides)) {
        $row['tld'] = strtolower((string) $row['name']);
    }

    return $row;
}

function assignStoreNodeRole(int $nodeId, string $role): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => $role,
        'status' => 'active',
    ]);
}

/**
 * @param  list<string>  $permissions
 */
function grantStoreNodeAccess(int $consumerId, int $servingId, array $permissions): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $consumerId,
        'serving_node_id' => $servingId,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('NodeStoreController', function (): void {
    it('returns registered validation JSON for accepted but pending node inputs', function (
        array $input,
        string $field,
        string $valueKey,
        string $value,
    ): void {
        $gatewayId = (int) DB::table('nodes')->insertGetId(apiStoreNodeRow());
        assignStoreNodeRole($gatewayId, 'gateway');

        Process::fake();
        Process::preventStrayProcesses();

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.6.0.2'])
            ->postJson('/api/nodes', [
                'name' => 'pending-1',
                'tld' => 'pending',
                ...$input,
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', $field)
            ->assertJsonPath('error.meta.reason', 'not_implemented')
            ->assertJsonPath("error.meta.{$valueKey}", $value);
        Process::assertRanTimes(fn (): bool => true, 0);
    })->with([
        'template s3' => [['template' => 's3'], 'template', 'template', 's3'],
        'template websocket' => [['template' => 'websocket'], 'template', 'template', 'websocket'],
        'role s3' => [['roles' => ['s3']], 'roles', 'role', 's3'],
        'role websocket' => [['roles' => ['websocket']], 'roles', 'role', 'websocket'],
    ]);

    it('rejects gateway-named callers without an active gateway assignment', function (): void {
        DB::table('nodes')->insert([
            apiStoreNodeRow([
                'name' => 'gateway-without-role',
                'host' => '10.6.0.2',
                'wireguard_address' => '10.6.0.2',
            ]),
        ]);

        Process::fake();
        Process::preventStrayProcesses();

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.6.0.2'])
            ->postJson('/api/nodes', [
                'name' => 'app-dev-1',
                'roles' => ['app-dev'],
                'host' => '192.0.2.20',
                'tld' => 'test',
            ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');

        Process::assertRanTimes(fn (): bool => true, 0);
    });

    it('rejects database callers before provisioning', function (): void {
        $gatewayId = (int) DB::table('nodes')->insertGetId(apiStoreNodeRow());
        assignStoreNodeRole($gatewayId, 'gateway');
        assignStoreNodeRole($gatewayId, 'vpn');

        $callerId = (int) DB::table('nodes')->insertGetId(apiStoreNodeRow([
            'name' => 'database-caller',
            'host' => '10.6.0.7',
            'wireguard_address' => '10.6.0.7',
        ]));
        assignStoreNodeRole($callerId, 'database');

        Process::fake();
        Process::preventStrayProcesses();

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.6.0.7'])
            ->postJson('/api/nodes', [
                'name' => 'app-dev-1',
                'roles' => ['app-dev'],
                'host' => '192.0.2.20',
                'tld' => 'test',
            ]);

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'node:new')
            ->assertJsonPath('error.meta.serving_node', 'gateway-1');

        expect(DB::table('nodes')->where('name', 'app-dev-1')->exists())->toBeFalse();
        Process::assertRanTimes(fn (): bool => true, 0);
    });

    it('allows assigned gateway callers through the gateway authority path', function (): void {
        $gatewayId = (int) DB::table('nodes')->insertGetId(apiStoreNodeRow([
            'name' => 'gateway',
        ]));
        assignStoreNodeRole($gatewayId, 'gateway');

        Process::fake();
        Process::preventStrayProcesses();

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.6.0.2'])
            ->postJson('/api/nodes', [
                'name' => 'gateway',
                'roles' => ['app-prod'],
                'host' => '192.0.2.20',
                'tld' => 'gateway',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'ingress_node');

        Process::assertRanTimes(fn (): bool => true, 0);
    });

    it('executes node creation in gateway context even when local config is stale', function (): void {
        $gatewayId = (int) DB::table('nodes')->insertGetId(apiStoreNodeRow([
            'name' => 'gateway',
        ]));
        assignStoreNodeRole($gatewayId, 'gateway');

        $callerId = (int) DB::table('nodes')->insertGetId(apiStoreNodeRow([
            'name' => 'control-1',
            'host' => '10.6.0.3',
            'wireguard_address' => '10.6.0.3',
            'gateway_endpoint' => '10.6.0.2',
            'user' => 'tester',
            'orbit_path' => '/home/tester/orbit',
        ]));
        grantStoreNodeAccess($callerId, $gatewayId, ['node:new']);

        Process::fake();
        Process::preventStrayProcesses();

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.6.0.3'])
            ->postJson('/api/nodes', [
                'name' => 'gateway',
                'template' => 'gateway',
                'host' => '192.0.2.20',
                'tld' => 'gateway',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.incompatible')
            ->assertJsonPath('error.message', 'Existing gateway is incompatible with the requested host or identity.');

        Process::assertRanTimes(fn (): bool => true, 0);
    });

    it('rejects ingress node for ingress node creation requests', function (): void {
        $gatewayId = (int) DB::table('nodes')->insertGetId(apiStoreNodeRow());
        assignStoreNodeRole($gatewayId, 'gateway');

        $callerId = (int) DB::table('nodes')->insertGetId(apiStoreNodeRow([
            'name' => 'control-1',
            'host' => '10.6.0.3',
            'wireguard_address' => '10.6.0.3',
            'gateway_endpoint' => '10.6.0.2',
            'user' => 'tester',
            'orbit_path' => '/home/tester/orbit',
        ]));
        grantStoreNodeAccess($callerId, $gatewayId, ['node:new']);

        Process::fake();
        Process::preventStrayProcesses();

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.6.0.3'])
            ->postJson('/api/nodes', [
                'name' => 'edge-1',
                'roles' => ['ingress'],
                'ingress_node' => 'other-edge-1',
                'host' => '192.0.2.21',
                'tld' => 'edge',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'ingress_node');

        Process::assertRanTimes(fn (): bool => true, 0);
    });

    it('rejects ingress node for colocated app-prod and ingress create-node requests', function (): void {
        $gatewayId = (int) DB::table('nodes')->insertGetId(apiStoreNodeRow());
        assignStoreNodeRole($gatewayId, 'gateway');

        $callerId = (int) DB::table('nodes')->insertGetId(apiStoreNodeRow([
            'name' => 'control-1',
            'host' => '10.6.0.3',
            'wireguard_address' => '10.6.0.3',
            'gateway_endpoint' => '10.6.0.2',
            'user' => 'tester',
            'orbit_path' => '/home/tester/orbit',
        ]));
        grantStoreNodeAccess($callerId, $gatewayId, ['node:new']);

        Process::fake();
        Process::preventStrayProcesses();

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.6.0.3'])
            ->postJson('/api/nodes', [
                'name' => 'web-1',
                'roles' => ['app-prod', 'ingress'],
                'ingress_node' => 'edge-1',
                'host' => '192.0.2.21',
                'tld' => 'web',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'ingress_node');

        Process::assertRanTimes(fn (): bool => true, 0);
    });

    it('requires authenticated control callers to use client-side bootstrap for an app node', function (): void {
        $gatewayId = (int) DB::table('nodes')->insertGetId(apiStoreNodeRow());
        assignStoreNodeRole($gatewayId, 'gateway');
        assignStoreNodeRole($gatewayId, 'vpn');

        $callerId = (int) DB::table('nodes')->insertGetId(apiStoreNodeRow([
            'name' => 'control-1',
            'host' => '10.6.0.3',
            'wireguard_address' => '10.6.0.3',
            'gateway_endpoint' => '10.6.0.2',
            'user' => 'tester',
            'orbit_path' => '/home/tester/orbit',
        ]));
        grantStoreNodeAccess($callerId, $gatewayId, ['node:new']);

        WireGuardPeer::query()->create([
            'node_id' => DB::table('nodes')->where('name', 'gateway-1')->value('id'),
            'public_key' => 'gateway-public-key',
            'private_key' => 'gateway-private-key',
            'pre_shared_key' => 'gateway-psk',
            'allowed_ips' => '10.6.0.2/32',
        ]);

        Process::fake(function ($process) {
            $command = (string) $process->command;

            if ($command === 'wg genkey') {
                return Process::result(output: "app-private-key\n");
            }

            if ($command === 'wg pubkey') {
                return Process::result(output: "app-public-key\n");
            }

            if (str_contains($command, 'ssh-keygen -y')) {
                return Process::result(output: "ssh-ed25519 AAAATEST gateway\n");
            }

            if (str_contains($command, 'wg show wg0 public-key')) {
                return Process::result(output: "wg-easy-public-key\n");
            }

            if (str_contains($command, 'internal:wg-easy:state')) {
                return Process::result(output: json_encode([
                    'success' => ['data' => [], 'meta' => []],
                ], JSON_THROW_ON_ERROR)
                    ."\n");
            }

            if (str_contains($command, 'com.docker.swarm.service.name=orbit_orbit-vpn')) {
                return Process::result(output: "vpn-container-id\n");
            }

            return Process::result();
        });
        Process::preventStrayProcesses();
        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.6.0.3'])
            ->postJson('/api/nodes', [
                'name' => 'app-dev-1',
                'roles' => ['app-dev'],
                'host' => '192.0.2.20',
                'tld' => 'test',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.bootstrap_required')
            ->assertJsonPath('error.meta.prepare_endpoint', '/api/nodes/bootstrap');

        expect(DB::table('nodes')->where('name', 'app-dev-1')->exists())->toBeFalse();
        Process::assertRanTimes(fn (): bool => true, 0);
    });

    it('does not inspect WireGuard reservations before directing legacy app creation to bootstrap', function (): void {
        $gatewayId = (int) DB::table('nodes')->insertGetId(apiStoreNodeRow());
        assignStoreNodeRole($gatewayId, 'gateway');
        assignStoreNodeRole($gatewayId, 'vpn');

        $callerId = (int) DB::table('nodes')->insertGetId(apiStoreNodeRow([
            'name' => 'control-1',
            'host' => '10.6.0.3',
            'wireguard_address' => '10.6.0.3',
            'gateway_endpoint' => '10.6.0.2',
            'user' => 'tester',
            'orbit_path' => '/home/tester/orbit',
        ]));
        grantStoreNodeAccess($callerId, $gatewayId, ['node:new']);

        DB::table('nodes')->insert(apiStoreNodeRow([
            'name' => 'database-1',
            'host' => '10.6.0.4',
            'wireguard_address' => '10.6.0.4',
        ]));

        WireGuardPeer::query()->create([
            'node_id' => $gatewayId,
            'public_key' => 'gateway-public-key',
            'private_key' => 'gateway-private-key',
            'pre_shared_key' => 'gateway-psk',
            'allowed_ips' => '10.6.0.2/32',
        ]);

        Process::fake(function ($process) {
            $command = (string) $process->command;

            if ($command === 'wg genkey') {
                return Process::result(output: "app-private-key\n");
            }

            if ($command === 'wg pubkey') {
                return Process::result(output: "app-public-key\n");
            }

            if (str_contains($command, 'ssh-keygen -y')) {
                return Process::result(output: "ssh-ed25519 AAAATEST gateway\n");
            }

            if (str_contains($command, 'wg show wg0 public-key')) {
                return Process::result(output: "wg-easy-public-key\n");
            }

            if (str_contains($command, 'wg show wg0 allowed-ips')) {
                return Process::result(output: "phone-public-key\t10.6.0.5/32\n");
            }

            if (str_contains($command, 'internal:wg-easy:state')) {
                return Process::result(output: json_encode([
                    'success' => ['data' => [], 'meta' => []],
                ], JSON_THROW_ON_ERROR)
                    ."\n");
            }

            if (str_contains($command, 'com.docker.swarm.service.name=orbit_orbit-vpn')) {
                return Process::result(output: "vpn-container-id\n");
            }

            return Process::result();
        });
        Process::preventStrayProcesses();
        app()->instance(RemoteShell::class, new NodeStoreConvergenceRemoteShell);

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.6.0.3'])
            ->postJson('/api/nodes', [
                'name' => 'app-dev-2',
                'roles' => ['app-dev'],
                'host' => '192.0.2.22',
                'tld' => 'test2',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.bootstrap_required');

        expect(DB::table('nodes')->where('name', 'app-dev-2')->exists())->toBeFalse();
        Process::assertRanTimes(fn (): bool => true, 0);
    });

    it('requires client-side bootstrap for a database host with a custom WireGuard endpoint', function (): void {
        $gatewayId = (int) DB::table('nodes')->insertGetId(apiStoreNodeRow([
            'gateway_endpoint' => '188.245.156.201',
        ]));
        assignStoreNodeRole($gatewayId, 'gateway');
        assignStoreNodeRole($gatewayId, 'vpn');

        $callerId = (int) DB::table('nodes')->insertGetId(apiStoreNodeRow([
            'name' => 'control-1',
            'host' => '10.6.0.3',
            'wireguard_address' => '10.6.0.3',
            'gateway_endpoint' => '10.6.0.2',
            'user' => 'tester',
            'orbit_path' => '/home/tester/orbit',
        ]));
        grantStoreNodeAccess($callerId, $gatewayId, ['node:new']);

        WireGuardPeer::query()->create([
            'node_id' => $gatewayId,
            'public_key' => 'gateway-public-key',
            'private_key' => 'gateway-private-key',
            'pre_shared_key' => 'gateway-psk',
            'allowed_ips' => '10.6.0.2/32',
        ]);

        $wireGuardConfigs = [];

        Process::fake(function ($process) use (&$wireGuardConfigs) {
            $command = (string) $process->command;

            if ($command === 'wg genkey') {
                return Process::result(output: "database-private-key\n");
            }

            if ($command === 'wg pubkey') {
                return Process::result(output: "database-public-key\n");
            }

            if (str_contains($command, 'ssh-keygen -y')) {
                return Process::result(output: "ssh-ed25519 AAAATEST gateway\n");
            }

            if (str_contains($command, 'wg show wg0 public-key')) {
                return Process::result(output: "wg-easy-public-key\n");
            }

            if (str_contains($command, 'internal:wg-easy:state')) {
                return Process::result(output: json_encode([
                    'success' => ['data' => [], 'meta' => []],
                ], JSON_THROW_ON_ERROR)
                    ."\n");
            }

            if (str_contains($command, 'com.docker.swarm.service.name=orbit_orbit-vpn')) {
                return Process::result(output: "vpn-container-id\n");
            }

            if (str_contains($command, 'wg-quick@wg-orbit')) {
                $wireGuardConfigs[] = (string) $process->input;
            }

            return Process::result();
        });
        Process::preventStrayProcesses();
        app()->instance(RemoteShell::class, new NodeStoreConvergenceRemoteShell);

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.6.0.3'])
            ->postJson('/api/nodes', [
                'name' => 'database1',
                'roles' => ['database'],
                'host' => '116.203.220.206',
                'tld' => 'db1',
                'user' => 'root',
                'gateway_endpoint' => '10.3.0.2',
                'host_key_fingerprint' => 'SHA256:database1',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.bootstrap_required')
            ->assertJsonPath('error.meta.host', '116.203.220.206');

        expect(DB::table('nodes')->where('name', 'database1')->exists())
            ->toBeFalse()
            ->and($wireGuardConfigs)
            ->toBe([]);
        Process::assertRanTimes(fn (): bool => true, 0);
    });

    it('rejects app callers before provisioning', function (): void {
        $gatewayId = (int) DB::table('nodes')->insertGetId(apiStoreNodeRow());
        assignStoreNodeRole($gatewayId, 'gateway');

        DB::table('nodes')->insert(apiStoreNodeRow([
            'name' => 'app-caller',
            'tld' => 'caller',
            'host' => '10.6.0.7',
            'wireguard_address' => '10.6.0.7',
            'gateway_endpoint' => '10.6.0.2',
        ]));
        assignStoreNodeRole((int) DB::table('nodes')->where('name', 'app-caller')->value('id'), 'app-dev');

        Process::fake();
        Process::preventStrayProcesses();

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.6.0.7'])
            ->postJson('/api/nodes', [
                'name' => 'app-dev-1',
                'roles' => ['app-dev'],
                'host' => '192.0.2.20',
                'tld' => 'test',
            ]);

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'node:new')
            ->assertJsonPath('error.meta.serving_node', 'gateway-1');

        expect(DB::table('nodes')->where('name', 'app-dev-1')->exists())->toBeFalse();
        Process::assertRanTimes(fn (): bool => true, 0);
    });

    it('does not use the legacy gateway path to adopt a compatible app node', function (): void {
        $gatewayId = (int) DB::table('nodes')->insertGetId(apiStoreNodeRow());
        assignStoreNodeRole($gatewayId, 'gateway');

        $callerId = (int) DB::table('nodes')->insertGetId(apiStoreNodeRow([
            'name' => 'control-1',
            'host' => '10.6.0.3',
            'wireguard_address' => '10.6.0.3',
            'gateway_endpoint' => '10.6.0.2',
            'user' => 'tester',
            'orbit_path' => '/home/tester/orbit',
        ]));
        grantStoreNodeAccess($callerId, $gatewayId, ['node:new']);

        $nodeId = DB::table('nodes')->insertGetId(apiStoreNodeRow([
            'name' => 'app-adopt-1',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'host' => '192.0.2.30',
            'wireguard_address' => '10.6.0.8',
            'gateway_endpoint' => '10.6.0.2',
            'user' => 'orbit',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'decommissioned',
        ]));

        WireGuardPeer::query()->create([
            'node_id' => $nodeId,
            'public_key' => 'app-public-key',
            'private_key' => 'app-private-key',
            'allowed_ips' => '10.6.0.8/32',
        ]);

        Process::fake([
            'sudo wg show wg-orbit allowed-ips' => Process::result(output: "app-public-key\t10.6.0.9/32\n"),
            'docker restart orbit-dns' => Process::result(),
        ]);
        Process::preventStrayProcesses();
        app()->instance(RemoteShell::class, new NodeStoreConvergenceRemoteShell);

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.6.0.3'])
            ->postJson('/api/nodes', [
                'name' => 'app-adopt-1',
                'roles' => ['app-dev'],
                'host' => '192.0.2.30',
                'tld' => 'test',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.bootstrap_required');

        $node = DB::table('nodes')->where('name', 'app-adopt-1')->first();

        expect($node)
            ->not
            ->toBeNull()
            ->and($node->status)
            ->toBe(NodeStatus::Decommissioned->value)
            ->and($node->wireguard_address)
            ->toBe('10.6.0.8');

        Process::assertRanTimes(fn (): bool => true, 0);
    });

    it('does not probe or materialize an unknown app host through the legacy gateway path', function (): void {
        $gatewayId = (int) DB::table('nodes')->insertGetId(apiStoreNodeRow());
        assignStoreNodeRole($gatewayId, 'gateway');
        assignStoreNodeRole($gatewayId, 'vpn');

        $callerId = (int) DB::table('nodes')->insertGetId(apiStoreNodeRow([
            'name' => 'control-1',
            'host' => '10.6.0.3',
            'wireguard_address' => '10.6.0.3',
            'gateway_endpoint' => '10.6.0.2',
            'user' => 'tester',
            'orbit_path' => '/home/tester/orbit',
        ]));
        grantStoreNodeAccess($callerId, $gatewayId, ['node:new']);

        $vpnInstaller = new class extends VpnDnsSwarmInstaller {
            /** @var list<array<string, string>> */
            public array $configuredPeers = [];

            public function __construct()
            {
                parent::__construct(rootPath: sys_get_temp_dir());
            }

            #[\Override]
            public function configurePeers(array $peers): void
            {
                $this->configuredPeers = $peers;
            }

            #[\Override]
            public function publicKey(): string
            {
                return 'wg-easy-public-key';
            }
        };
        app()->instance(VpnDnsSwarmInstaller::class, $vpnInstaller);

        app()->instance(RemoteShell::class, new NodeStoreSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "app-public-key\n", stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'name' => 'app-unknown-1',
                    'role' => 'app-dev',
                    'local_role' => 'app-dev',
                    'status' => 'active',
                    'platform' => 'ubuntu_24-04',
                    'wireguard_address' => '10.6.0.8',
                    'registry_public_key' => null,
                    'interface_public_key' => 'app-public-key',
                ], JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: "app-public-key\n", stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'name' => 'app-unknown-1',
                    'role' => 'app-dev',
                    'local_role' => 'app-dev',
                    'status' => 'active',
                    'platform' => 'ubuntu_24-04',
                    'wireguard_address' => '10.6.0.8',
                    'registry_public_key' => null,
                    'interface_public_key' => 'app-public-key',
                ], JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
        ]));

        Process::fake(function ($process) {
            $command = (string) $process->command;

            if ($command === 'sudo wg show wg-orbit allowed-ips') {
                return Process::result(output: "app-public-key\t10.6.0.8/32\n");
            }

            if ($command === 'wg genkey') {
                return Process::result(output: "app-private-key\n");
            }

            if ($command === 'wg pubkey') {
                return Process::result(output: "app-public-key\n");
            }

            if ($command === "docker ps -q --filter 'label=com.docker.swarm.service.name=orbit_orbit-vpn'") {
                return Process::result(output: "vpn-container-id\n");
            }

            if ($command === "docker exec 'vpn-container-id' wg show wg0 allowed-ips") {
                return Process::result(output: "app-public-key\t10.6.0.8/32\n");
            }

            if ($command === "docker exec 'vpn-container-id' wg show wg0 public-key") {
                return Process::result(output: "wg-easy-public-key\n");
            }

            if ($command === "docker service inspect 'orbit_orbit-dns'") {
                return Process::result(exitCode: 1);
            }

            if ($command === 'docker restart orbit-dns') {
                return Process::result();
            }

            if (str_contains($command, 'ssh-keygen -y')) {
                return Process::result(output: "ssh-ed25519 AAAATEST gateway\n");
            }

            if (str_starts_with($command, 'tar ')) {
                return Process::result();
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.6.0.3'])
            ->postJson('/api/nodes', [
                'name' => 'app-unknown-1',
                'roles' => ['app-dev'],
                'host' => '192.0.2.33',
                'tld' => 'test',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.bootstrap_required');

        expect(DB::table('nodes')->where('name', 'app-unknown-1')->exists())
            ->toBeFalse()
            ->and($vpnInstaller->configuredPeers)
            ->toBe([]);
        Process::assertRanTimes(fn (): bool => true, 0);
    });
});

final class NodeStoreSequencedRemoteShell implements RemoteShell
{
    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if (str_contains($script, 'internal:wg-easy:state')) {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode(['success' => ['data' => [], 'meta' => []]], JSON_THROW_ON_ERROR)."\n",
                stderr: '',
                durationMs: 1,
            );
        }

        if (str_contains($script, '# orbit-tool-probe:capability')) {
            return new NodeStoreConvergenceRemoteShell()->run($node, $script, $options);
        }

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class NodeStoreConvergenceRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $toolNodeStatuses = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if (str_contains($script, 'internal:wg-easy:state')) {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode(['success' => ['data' => [], 'meta' => []]], JSON_THROW_ON_ERROR)."\n",
                stderr: '',
                durationMs: 1,
            );
        }

        if (! str_contains($script, '# orbit-tool-probe:capability')) {
            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }

        $this->toolNodeStatuses[] = $node->status->value;
        $payload = json_decode((string) ($options['input'] ?? ''), associative: true, flags: JSON_THROW_ON_ERROR);

        if (is_string($payload['script'] ?? null)) {
            $payload = [
                'tools' => [
                    'caddy' => ['binary' => 'docker', 'container' => 'orbit-caddy'],
                    'composer' => ['binary' => '/usr/local/bin/composer'],
                    'gh' => ['binary' => 'gh'],
                    'git' => ['binary' => 'git'],
                    'laravel-installer' => ['binary' => '/usr/local/bin/laravel'],
                    'php-cli' => ['binary' => '/opt/orbit/php/8.5/bin/php'],
                ],
            ];
        }

        if (is_array($payload['tools'] ?? null)) {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: collect($payload['tools'])
                    ->map(fn (mixed $tool, string $name): string => json_encode([
                        'name' => $name,
                        ...$this->toolProbePayload($node, is_array($tool) ? $tool : []),
                    ], JSON_THROW_ON_ERROR))
                    ->implode("\n")."\n",
                stderr: '',
                durationMs: 1,
            );
        }

        $binary = is_string($payload['binary'] ?? null) ? $payload['binary'] : '';
        $container = is_string($payload['container'] ?? null) ? $payload['container'] : '';

        return new RemoteShellResult(
            exitCode: 0,
            stdout: $this->toolProbeTabOutput($node, [
                'binary' => $binary,
                'container' => $container,
            ]),
            stderr: '',
            durationMs: 1,
        );
    }

    /**
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private function toolProbePayload(Node $node, array $tool): array
    {
        $binary = is_string($tool['binary'] ?? null) ? $tool['binary'] : '';
        $container = is_string($tool['container'] ?? null) ? $tool['container'] : '';

        if ($container === 'orbit-caddy') {
            $hash = OrbitCaddyContainer::forPrivateNode((string) $node->wireguard_address)->specHash();

            return [
                'installed' => true,
                'path' => '/usr/bin/docker',
                'version' => 'Docker version 27.0.0',
                'state' => 'running',
                'config_exists' => null,
                'config_hash' => null,
                'secret_exists' => null,
                'secret_hash' => null,
                'container_exists' => true,
                'container_state' => 'running',
                'container_spec_hash' => $hash,
            ];
        }

        [$path, $version] = match ($binary) {
            '/opt/orbit/php/8.5/bin/php' => ['/opt/orbit/php/8.5/bin/php', '8.5.6'],
            '/usr/local/bin/composer' => ['/usr/local/bin/composer', 'Composer version 2.9.0'],
            'gh' => ['/usr/bin/gh', 'gh version 2.60.0'],
            'git' => ['/usr/bin/git', 'git version 2.53.0'],
            'laravel', '/usr/local/bin/laravel', 'laravel-installer' => [
                '/usr/local/bin/laravel',
                'Laravel Installer 5.0.0',
            ],
            default => ['', ''],
        };

        return [
            'installed' => $path !== '',
            'path' => $path !== '' ? $path : null,
            'version' => $version !== '' ? $version : null,
            'state' => 'unknown',
            'config_exists' => null,
            'config_hash' => null,
            'secret_exists' => null,
            'secret_hash' => null,
            'container_exists' => null,
            'container_state' => null,
            'container_spec_hash' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $tool
     */
    private function toolProbeTabOutput(Node $node, array $tool): string
    {
        $payload = $this->toolProbePayload($node, $tool);

        return implode("\t", [
            $payload['path'] ?? '',
            $payload['version'] ?? '',
            $payload['state'] ?? '',
            $payload['config_exists'] === null ? '' : ($payload['config_exists'] ? '1' : '0'),
            $payload['config_hash'] ?? '',
            $payload['secret_exists'] === null ? '' : ($payload['secret_exists'] ? '1' : '0'),
            $payload['secret_hash'] ?? '',
            $payload['container_exists'] === null ? '' : ($payload['container_exists'] ? '1' : '0'),
            $payload['container_state'] ?? '',
            $payload['container_spec_hash'] ?? '',
        ])."\n";
    }
}
