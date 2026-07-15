<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\WireGuardPeer;
use App\Services\Nodes\NodeBootstrapBundleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders an idempotent minimal WireGuard CLI and Agent bootstrap bundle', function (): void {
    config()->set('orbit.updates.manifest_snapshot', nodeBootstrapReleaseManifest());

    $gateway = Node::factory()->create([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.2',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $gateway->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    $node = Node::factory()->create([
        'name' => 'app-dev-1',
        'host' => '192.0.2.20',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.4',
        'user' => 'orbit',
        'status' => 'provisioning',
        'managed' => true,
    ]);
    $peer = WireGuardPeer::query()->create([
        'node_id' => $node->id,
        'public_key' => 'node-public-key',
        'private_key' => 'node-private-key',
        'pre_shared_key' => 'node-preshared-key',
        'allowed_ips' => '10.6.0.4/32',
    ]);

    $script = app(NodeBootstrapBundleBuilder::class)->build(
        node: $node,
        gateway: $gateway,
        peer: $peer,
        wireguardConfig: <<<'WG'
            [Interface]
            PrivateKey = node-private-key
            Address = 10.6.0.4/24

            [Peer]
            PublicKey = gateway-public-key
            AllowedIPs = 10.6.0.0/24
            Endpoint = gateway.example.com:51820
            WG,
    );

    expect($script)
        ->toContain('set -euo pipefail')
        ->toContain("RUNTIME_USER='orbit'")
        ->toContain('if ! command -v sudo')
        ->toContain('install -y -qq sudo')
        ->toContain('useradd -m -s /bin/bash')
        ->toContain('wireguard wireguard-tools')
        ->toContain('/etc/wireguard/wg-orbit.conf')
        ->toContain('wg-quick@wg-orbit')
        ->toContain('https://artifacts.orbit.test/orbit-linux-x64')
        ->toContain(str_repeat('b', 64))
        ->toContain('/home/orbit/.local/bin/orbit')
        ->toContain('https://artifacts.orbit.test/orbit-agent-linux-x64')
        ->toContain(str_repeat('d', 64))
        ->toContain('/home/orbit/.local/bin/orbit-agent')
        ->toContain('ORBIT_AGENT_ORBIT_BINARY=/home/orbit/.local/bin/orbit')
        ->toContain('ORBIT_AGENT_HTTP_BIND=10.6.0.4:9477')
        ->toContain('After=network-online.target wg-quick@wg-orbit.service')
        ->not->toContain('ssh ')
        ->not->toContain('scp ');
});

/**
 * @return array<string, mixed>
 */
function nodeBootstrapReleaseManifest(): array
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
