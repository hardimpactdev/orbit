<?php

declare(strict_types=1);

use App\Domain\Nodes\NativeNodeRoleRemovalInput;
use App\Domain\Nodes\NodeRolePublicationInput;
use App\Domain\Nodes\RoleName;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(fn () => SwampNodeFixture::ensure());

function publishingNode(string $name, string $address, RoleName $role): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => 'orbit', 'wireguard_public_key' => $name.'-key',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => $name.'-host-key', 'ssh_host_fingerprint' => 'SHA256:'.$name]));
    $node->roles()->create(['role' => $role, 'status' => 'active']);

    return $node;
}

describe('role publications and private DNS', function (): void {
    it('gives each vpn Node the complete private DNS as the state of its publication', function (): void {
        $vpn = publishingNode('vpn', '10.44.0.1', RoleName::Vpn);
        $metrics = publishingNode('metrics', '10.44.0.3', RoleName::Metrics);

        $publications = app(NativeNodeRoleRemovalInput::class)->publications($metrics, RoleName::Metrics);
        $dns = array_find($publications, static fn (array $publication): bool => $publication['node_id'] === $vpn->id)['dns'];

        expect($dns)->toHaveKeys(['revision', 'listen', 'tlds', 'records'])
            ->and($dns['listen'])->toBe(['10.44.0.1'])
            ->and(array_column($publications, 'node_id'))->toContain($vpn->id);
    });

    it('carries the render of a vpn participant in its public input, and no more than the state', function (): void {
        $vpn = publishingNode('vpn', '10.44.0.1', RoleName::Vpn);

        $input = app(NodeRolePublicationInput::class)->forPlan(['caddy_node_ids' => [], 'php_node_ids' => [], 'dns_node_ids' => [$vpn->id]], $vpn);

        expect(array_keys($input['private_dns_publication']))->toBe(['revision', 'listen', 'tlds', 'records']);
    });
});
