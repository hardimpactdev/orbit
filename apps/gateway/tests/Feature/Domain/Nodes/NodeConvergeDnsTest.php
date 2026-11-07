<?php

declare(strict_types=1);

use App\Domain\Fleet\NodeFootprint;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Nodes\NodeConvergeInput;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

function convergeInputFor(): NodeConvergeInput
{
    $delivery = app(AgentDelivery::class);
    $artifact = Mockery::mock(NodeFootprintArtifact::class);
    $artifact->shouldReceive('name')->andReturn('caddy-package');
    $artifact->shouldReceive('applies')->andReturn(true);
    $artifact->shouldReceive('digest')->andReturn('caddy');

    return new NodeConvergeInput(new NodeFootprint($delivery, [$artifact]), $delivery, [$artifact]);
}

function convergeNode(string $name, string $address, ?RoleName $role): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => 'orbit', 'wireguard_public_key' => $name.'-key',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => $name.'-host-key', 'ssh_host_fingerprint' => 'SHA256:'.$name]));
    if ($role instanceof RoleName) {
        $node->roles()->create(['role' => $role, 'status' => 'active']);
    }

    return $node;
}

describe('node:converge and private DNS', function (): void {
    beforeEach(fn () => SwampNodeFixture::ensure());

    it('republishes the complete private DNS of a vpn Node with a fresh entry, never as a footprint artifact', function (): void {
        $vpn = convergeNode('vpn', '10.44.0.1', RoleName::Vpn);
        $input = convergeInputFor();

        $first = $input->captureNative($vpn, false, false);
        $second = $input->captureNative($vpn, false, false);
        $dns = $first['roles']['dns'];

        expect($dns)->toHaveKeys(['node_id', 'control_node_id', 'connection', 'host_keys', 'dns'])
            ->and($dns['node_id'])->toBe($vpn->id)
            ->and($dns['dns'])->toHaveKeys(['revision', 'listen', 'tlds', 'records'])
            ->and((int) $second['roles']['dns']['dns']['revision'])->toBeGreaterThan((int) $dns['dns']['revision'])
            ->and(array_column($first['artifacts'], 'name'))->not->toContain('private-dns')
            ->and($first['payloads'])->not->toHaveKey('private-dns')
            ->and(json_encode($first))->not->toContain('catalog');
    });

    it('publishes no private DNS for a Node that is not a vpn Node', function (): void {
        $node = convergeNode('app', '10.44.0.4', RoleName::AppProd);

        expect(convergeInputFor()->captureNative($node, false, false)['roles']['dns'])->toBeNull();
    });
});
