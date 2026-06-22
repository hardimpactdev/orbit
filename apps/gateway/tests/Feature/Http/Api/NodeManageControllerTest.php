<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Data\Security\PinnedHostKey;
use App\Models\Node;
use App\Services\Security\SshHostKeyPinner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

const NODE_MANAGE_CALLER_WG_IP = '10.44.0.24';

describe('node self management API', function (): void {
    it('returns the gateway management SSH public key for active roleless callers', function (): void {
        Node::factory()->operator()->create([
            'name' => 'mini',
            'host' => NODE_MANAGE_CALLER_WG_IP,
            'wireguard_address' => NODE_MANAGE_CALLER_WG_IP,
            'status' => 'active',
        ]);

        Process::fake([
            '*' => Process::result(output: "ssh-ed25519 AAAAC3NzaGatewayKey orbit-gateway\n"),
        ]);

        $response = $this->call('GET', '/api/nodes/self/manage-key', [], [], [], [
            'REMOTE_ADDR' => NODE_MANAGE_CALLER_WG_IP,
        ]);

        $response->assertOk()
            ->assertJsonPath('success.data.management_ssh_key.public_key', 'ssh-ed25519 AAAAC3NzaGatewayKey orbit-gateway');
    });

    it('persists user and platform, pins by WireGuard address, and verifies SSH reachability', function (): void {
        $node = Node::factory()->operator()->create([
            'name' => 'mini',
            'host' => NODE_MANAGE_CALLER_WG_IP,
            'wireguard_address' => NODE_MANAGE_CALLER_WG_IP,
            'status' => 'active',
        ]);
        $shell = new NodeManageRecordingShell;
        $pinner = new NodeManageRecordingHostKeyPinner;
        app()->instance(RemoteShell::class, $shell);
        app()->instance(SshHostKeyPinner::class, $pinner);

        $response = $this->call('POST', '/api/nodes/self/manage', [
            'user' => 'nicky',
            'platform' => 'macos_15-5',
        ], [], [], ['REMOTE_ADDR' => NODE_MANAGE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.management.node', 'mini')
            ->assertJsonPath('success.data.management.user', 'nicky')
            ->assertJsonPath('success.data.management.platform', 'macos_15-5')
            ->assertJsonPath('success.data.management.ssh_host', NODE_MANAGE_CALLER_WG_IP)
            ->assertJsonPath('success.data.management.host_key_pinned', true)
            ->assertJsonPath('success.data.management.ssh_verified', true);

        expect($node->fresh())
            ->user->toBe('nicky')
            ->platform->toBe('macos_15-5')
            ->host_key_fingerprint->toBe('SHA256:test')
            ->and($pinner->hosts)->toBe([NODE_MANAGE_CALLER_WG_IP])
            ->and($shell->nodes)->toBe(['mini'])
            ->and($shell->scripts[0] ?? '')->toContain('true');
    });

    it('rejects role-bearing callers before management side effects', function (): void {
        createTestGatewayNode([
            'name' => 'gateway-1',
            'host' => NODE_MANAGE_CALLER_WG_IP,
            'wireguard_address' => NODE_MANAGE_CALLER_WG_IP,
            'status' => 'active',
        ]);
        $shell = new NodeManageRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call('POST', '/api/nodes/self/manage', [
            'user' => 'orbit',
            'platform' => 'ubuntu_24-04',
        ], [], [], ['REMOTE_ADDR' => NODE_MANAGE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.not_operator');

        expect($shell->scripts)->toBe([]);
    });

});

final class NodeManageRecordingShell implements RemoteShell
{
    /** @var list<string> */
    public array $nodes = [];

    /** @var list<string> */
    public array $scripts = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node->name;
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class NodeManageRecordingHostKeyPinner
{
    /** @var list<string> */
    public array $hosts = [];

    public function pin(string $host, ?string $expectedFingerprint = null): PinnedHostKey
    {
        $this->hosts[] = $host;

        return new PinnedHostKey(
            host: $host,
            type: 'ssh-ed25519',
            publicKey: 'AAAAC3NzaManagedHostKey',
            fingerprint: 'SHA256:test',
            pinMode: 'tofu',
        );
    }

    public function persist(Node $node, PinnedHostKey $key): void
    {
        $node->forceFill([
            'host_key_type' => $key->type,
            'host_key_public' => $key->publicKey,
            'host_key_fingerprint' => $key->fingerprint,
            'host_key_pin_mode' => $key->pinMode,
            'host_key_pinned_at' => now(),
        ])->save();
    }
}
