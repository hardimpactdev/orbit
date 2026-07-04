<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
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
            'status' => NodeStatus::Active,
            'orbit_agent_capable' => true,
        ]);
}

function activeAgentUnavailableNode(): Node
{
    return Node::factory()
        ->create([
            'name' => 'agent-unavailable-node',
            'host' => 'agent-unavailable.test',
            'status' => NodeStatus::Active,
            'orbit_agent_capable' => false,
        ]);
}

function inactiveCapableNode(): Node
{
    return Node::factory()
        ->create([
            'name' => 'inactive-capable',
            'host' => 'inactive.test',
            'status' => NodeStatus::Inactive,
            'orbit_agent_capable' => true,
        ]);
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

it('selects agent-push under auto when node is active, orbit_agent_capable, and envelope supports agent push', function (): void {
    $node = activeCapableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:executor:verify', supportsAgentPushTransport: true);

    expect($selector->select($node, $envelope, NodeTransportPreference::Auto))->toBe(NodeTransport::AgentPush);
});

it('uses transitional ssh fallback under auto while node lacks agent capability during migration', function (): void {
    $node = activeAgentUnavailableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:executor:verify', supportsAgentPushTransport: true);

    expect($selector->select($node, $envelope, NodeTransportPreference::Auto))
        ->toBe(NodeTransport::TransitionalSshFallback);
});

it('uses transitional ssh fallback under auto when node is not active even if agent capable', function (): void {
    $node = inactiveCapableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:executor:verify', supportsAgentPushTransport: true);

    expect($selector->select($node, $envelope, NodeTransportPreference::Auto))
        ->toBe(NodeTransport::TransitionalSshFallback);
});

it('selects agent-push when explicitly requested and conditions are met', function (): void {
    $node = activeCapableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:executor:verify', supportsAgentPushTransport: true);

    expect($selector->select($node, $envelope, NodeTransportPreference::AgentPush))->toBe(NodeTransport::AgentPush);
});

it('uses transitional ssh fallback for explicit agent-push request when node cannot support agent push during v1', function (): void {
    $node = activeAgentUnavailableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:executor:verify', supportsAgentPushTransport: true);

    expect($selector->select($node, $envelope, NodeTransportPreference::AgentPush))
        ->toBe(NodeTransport::TransitionalSshFallback);
});

it('routes unsupported node-executing envelopes to transitional ssh fallback during migration', function (): void {
    $node = activeCapableNode();
    $selector = new NodeCommandTransportSelector;
    $envelope = NodeCommandEnvelope::nodeExecuting('internal:agent-unsupported');

    expect($selector->select($node, $envelope, NodeTransportPreference::Auto))
        ->toBe(NodeTransport::TransitionalSshFallback);
    expect($selector->select($node, $envelope, NodeTransportPreference::AgentPush))
        ->toBe(NodeTransport::TransitionalSshFallback);
});
