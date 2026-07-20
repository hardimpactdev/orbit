<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\NodeCommandTransport\NodeCommandEnvelope;
use App\Services\NodeCommandTransport\NodeCommandTransportSelector;
use App\Services\NodeCommandTransport\NodeTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function activeCapableNode(): Node
{
    return Node::factory()
        ->create([
            'name' => 'capable-node',
            'host' => 'capable.test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.44.0.10',
            'status' => NodeStatus::Active,
            'managed' => true,
        ]);
}

function activeAgentUnavailableNode(): Node
{
    return Node::factory()
        ->create([
            'name' => 'agent-unavailable-node',
            'host' => 'agent-unavailable.test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.44.0.11',
            'status' => NodeStatus::Active,
            'managed' => false,
        ]);
}

function inactiveCapableNode(): Node
{
    return Node::factory()
        ->create([
            'name' => 'inactive-capable',
            'host' => 'inactive.test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.44.0.12',
            'status' => NodeStatus::Inactive,
            'managed' => true,
        ]);
}

function active_gateway_node(): Node
{
    $node = Node::factory()->create([
        'name' => 'gateway-node',
        'host' => 'gateway.test',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.44.0.13',
        'status' => NodeStatus::Active,
        'managed' => false,
    ]);

    if (! $node instanceof Node) {
        throw new RuntimeException('Expected node factory to create a node model.');
    }

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    return $node;
}

it('selects gateway-only for gateway-owned reads like project:list', function (): void {
    $node = activeCapableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::gatewayOnlyRead('project:list');

    expect($selector->select($node, $envelope))->toBe(NodeTransport::GatewayOnly);
});

it('selects gateway-only for gateway targets even when the envelope requires node execution', function (): void {
    $node = active_gateway_node();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting(
        'internal:executor:verify',
        supportsAgentPushTransport: true,
    );

    expect($selector->select($node, $envelope))->toBe(NodeTransport::GatewayOnly);
});

it('defaults to auto selection semantics when transport arg omitted', function (): void {
    $node = activeCapableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:executor:verify', supportsAgentPushTransport: true);

    expect($selector->select($node, $envelope))->toBe(NodeTransport::AgentPush);
});

it('selects agent-push when node is active, managed, and the envelope supports it', function (): void {
    $node = activeCapableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:executor:verify', supportsAgentPushTransport: true);

    expect($selector->select($node, $envelope))->toBe(NodeTransport::AgentPush);
});

it('fails while node lacks agent capability instead of falling back to ssh', function (): void {
    $node = activeAgentUnavailableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:executor:verify', supportsAgentPushTransport: true);

    expect(fn () => $selector->select($node, $envelope))
        ->toThrow(RuntimeException::class, 'agent-push transport is unavailable');
});

it('treats the omitted transport preference as auto and refuses ssh fallback for incapable nodes', function (): void {
    $node = activeAgentUnavailableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:executor:verify', supportsAgentPushTransport: true);

    expect(fn () => $selector->select($node, $envelope))
        ->toThrow(RuntimeException::class, 'agent-push transport is unavailable');
});

it('fails when node is not active instead of falling back to ssh', function (): void {
    $node = inactiveCapableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:executor:verify', supportsAgentPushTransport: true);

    expect(fn () => $selector->select($node, $envelope))
        ->toThrow(RuntimeException::class, 'agent-push transport is unavailable');
});

it('selects agent-push when conditions are met', function (): void {
    $node = activeCapableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:executor:verify', supportsAgentPushTransport: true);

    expect($selector->select($node, $envelope))->toBe(NodeTransport::AgentPush);
});

it('fails when node cannot support agent push instead of falling back to ssh', function (): void {
    $node = activeAgentUnavailableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:executor:verify', supportsAgentPushTransport: true);

    expect(fn () => $selector->select($node, $envelope))
        ->toThrow(RuntimeException::class, 'agent-push transport is unavailable');
});

it('fails unsupported node-executing envelopes without an ssh fallback', function (): void {
    $node = activeCapableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:agent-unsupported');

    expect(fn () => $selector->select($node, $envelope))
        ->toThrow(RuntimeException::class, 'agent-push transport is unavailable');
});
