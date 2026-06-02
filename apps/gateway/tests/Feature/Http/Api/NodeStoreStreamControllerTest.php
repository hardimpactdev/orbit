<?php

declare(strict_types=1);

use App\Data\Security\PinnedHostKey;
use App\Models\NodeRoleAssignment;
use App\Models\OperationRun;
use App\Models\WireGuardPeer;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Security\SshHostKeyPinner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    bindDevelopmentDnsMappingTestDoubles('node-store-stream-controller-dns');

    app()->instance(SshHostKeyPinner::class, new class
    {
        public function pin(string $host, ?string $expectedFingerprint = null): PinnedHostKey
        {
            return new PinnedHostKey(
                host: $host,
                type: 'ssh-ed25519',
                publicKey: 'AAAAC3NzaC1lZDI1NTE5AAAAIMockEd25519KeyForOrbitTests',
                fingerprint: $expectedFingerprint ?? 'SHA256:node-store-stream-test',
                pinMode: $expectedFingerprint === null ? 'tofu' : 'verified',
            );
        }
    });
});

afterEach(function (): void {
    File::deleteDirectory(app(DevelopmentDnsMappingEnactor::class)->configDir());
});

function nodeStoreStreamRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'gateway-1',
        'tld' => null,
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
}

function assignNodeStoreStreamRole(int $nodeId, string $role): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => $role,
        'status' => 'active',
    ]);
}

function grantNodeStoreStreamAccess(int $consumerId, int $servingId): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $consumerId,
        'serving_node_id' => $servingId,
        'permissions' => json_encode(['node:new'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('streams node creation from an operation_run source', function (): void {
    $gatewayId = (int) DB::table('nodes')->insertGetId(nodeStoreStreamRow());
    assignNodeStoreStreamRole($gatewayId, 'gateway');
    assignNodeStoreStreamRole($gatewayId, 'vpn');

    $callerId = (int) DB::table('nodes')->insertGetId(nodeStoreStreamRow([
        'name' => 'control-1',
        'host' => '10.6.0.3',
        'wireguard_address' => '10.6.0.3',
        'gateway_endpoint' => '10.6.0.2',
        'user' => 'tester',
        'orbit_path' => '/home/tester/orbit',
    ]));
    grantNodeStoreStreamAccess($callerId, $gatewayId);

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
            return Process::result(output: json_encode(['success' => ['data' => [], 'meta' => []]], JSON_THROW_ON_ERROR)."\n");
        }

        return Process::result();
    });
    Process::preventStrayProcesses();

    $response = $this->call('POST', '/api/nodes', [
        'name' => 'app-dev-1',
        'roles' => ['app-dev'],
        'host' => '192.0.2.20',
        'tld' => 'test',
    ], [], [], [
        'HTTP_ACCEPT' => 'text/event-stream',
        'REMOTE_ADDR' => '10.6.0.3',
    ]);

    $response->assertOk();

    $content = $response->streamedContent();
    $operationRun = OperationRun::query()->where('operation_type', 'node:new')->firstOrFail();

    expect($content)->toContain('event: tree')
        ->and($content)->toContain('Record operation state')
        ->and($content)->toContain('Run node creation')
        ->and($content)->toContain('event: complete')
        ->and($content)->toContain($operationRun->id)
        ->and($operationRun->status->value)->toBe('succeeded')
        ->and($operationRun->caller_node_id)->toBe($callerId)
        ->and($operationRun->result['success']['data']['node']['name'])->toBe('app-dev-1');
});
