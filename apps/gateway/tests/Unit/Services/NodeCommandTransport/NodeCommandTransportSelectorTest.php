<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\NodeCommandTransport\NodeCommandEnvelope;
use App\Services\NodeCommandTransport\NodeCommandTransportSelector;
use App\Services\NodeCommandTransport\NodeTransport;
use App\Services\NodeCommandTransport\NodeTransportPreference;
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

it('selects gateway-only for gateway-owned reads like app:list regardless of requested transport', function (): void {
    $node = activeCapableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::gatewayOnlyRead('app:list');

    expect($selector->select($node, $envelope))->toBe(NodeTransport::GatewayOnly);
    expect($selector->select($node, $envelope, NodeTransportPreference::TransitionalSshFallback))
        ->toBe(NodeTransport::GatewayOnly);
    expect($selector->select($node, $envelope, NodeTransportPreference::AgentPush))->toBe(NodeTransport::GatewayOnly);
});

it('selects gateway-only for gateway targets even when the envelope requires node execution', function (): void {
    $node = active_gateway_node();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting(
        'internal:executor:verify',
        supportsAgentPushTransport: true,
    );

    expect($selector->select($node, $envelope))->toBe(NodeTransport::GatewayOnly);
    expect($selector->select($node, $envelope, NodeTransportPreference::TransitionalSshFallback))
        ->toBe(NodeTransport::GatewayOnly);
    expect($selector->select($node, $envelope, NodeTransportPreference::AgentPush))
        ->toBe(NodeTransport::GatewayOnly);
});

it('defaults to auto selection semantics when transport arg omitted', function (): void {
    $node = activeCapableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:executor:verify', supportsAgentPushTransport: true);

    expect($selector->select($node, $envelope))->toBe(NodeTransport::AgentPush);
});

it('selects transitional ssh fallback only when explicitly requested for node-executing commands', function (): void {
    $node = activeCapableNode();
    $selector = new NodeCommandTransportSelector;
    $agentEnvelope = NodeCommandEnvelope::nodeExecuting('internal:executor:verify', supportsAgentPushTransport: true);
    $unsupportedEnvelope = NodeCommandEnvelope::nodeExecuting('internal:agent-unsupported');

    expect($selector->select($node, $agentEnvelope, NodeTransportPreference::TransitionalSshFallback))
        ->toBe(NodeTransport::TransitionalSshFallback);
    expect($selector->select($node, $unsupportedEnvelope, NodeTransportPreference::TransitionalSshFallback))
        ->toBe(NodeTransport::TransitionalSshFallback);
});

it('selects agent-push under auto when node is active, managed, and envelope supports agent push', function (): void {
    $node = activeCapableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:executor:verify', supportsAgentPushTransport: true);

    expect($selector->select($node, $envelope, NodeTransportPreference::Auto))->toBe(NodeTransport::AgentPush);
});

it('fails under auto while node lacks agent capability instead of silently falling back to ssh', function (): void {
    $node = activeAgentUnavailableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:executor:verify', supportsAgentPushTransport: true);

    expect(fn () => $selector->select($node, $envelope, NodeTransportPreference::Auto))
        ->toThrow(RuntimeException::class, 'agent-push transport is unavailable');
});

it('treats the omitted transport preference as auto and refuses ssh fallback for incapable nodes', function (): void {
    $node = activeAgentUnavailableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:executor:verify', supportsAgentPushTransport: true);

    expect(fn () => $selector->select($node, $envelope))
        ->toThrow(RuntimeException::class, 'agent-push transport is unavailable');
});

it('fails under auto when node is not active instead of silently falling back to ssh', function (): void {
    $node = inactiveCapableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:executor:verify', supportsAgentPushTransport: true);

    expect(fn () => $selector->select($node, $envelope, NodeTransportPreference::Auto))
        ->toThrow(RuntimeException::class, 'agent-push transport is unavailable');
});

it('selects agent-push when explicitly requested and conditions are met', function (): void {
    $node = activeCapableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:executor:verify', supportsAgentPushTransport: true);

    expect($selector->select($node, $envelope, NodeTransportPreference::AgentPush))->toBe(NodeTransport::AgentPush);
});

it('fails explicit agent-push request when node cannot support agent push instead of falling back to ssh', function (): void {
    $node = activeAgentUnavailableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:executor:verify', supportsAgentPushTransport: true);

    expect(fn () => $selector->select($node, $envelope, NodeTransportPreference::AgentPush))
        ->toThrow(RuntimeException::class, 'agent-push transport is unavailable');
});

it('fails unsupported node-executing envelopes unless ssh fallback is explicitly requested', function (): void {
    $node = activeCapableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:agent-unsupported');

    expect(fn () => $selector->select($node, $envelope, NodeTransportPreference::Auto))
        ->toThrow(RuntimeException::class, 'agent-push transport is unavailable');
    expect(fn () => $selector->select($node, $envelope, NodeTransportPreference::AgentPush))
        ->toThrow(RuntimeException::class, 'agent-push transport is unavailable');
    expect($selector->select($node, $envelope, NodeTransportPreference::TransitionalSshFallback))
        ->toBe(NodeTransport::TransitionalSshFallback);
});
