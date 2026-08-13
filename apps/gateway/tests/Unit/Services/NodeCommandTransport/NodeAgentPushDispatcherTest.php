<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Services\NodeCommandTransport\NodeAgentPushClient;
use App\Services\NodeCommandTransport\NodeAgentPushDispatcher;
use App\Services\NodeCommandTransport\NodeCommandEnvelope;
use App\Services\NodeCommandTransport\NodeCommandTransportSelector;
use App\Services\NodeCommandTransport\NodeTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function node_agent_push_dispatcher_test_node(string $address = '10.6.0.41'): Node
{
    return Node::factory()->create([
        'name' => 'agent-push-dispatcher-node',
        'host' => 'agent-push-dispatcher.test',
        'wireguard_address' => $address,
        'status' => NodeStatus::Active,
        'managed' => true,
    ]);
}

function node_agent_push_dispatcher_test_subject(): NodeAgentPushDispatcher
{
    return new NodeAgentPushDispatcher(
        selector: new NodeCommandTransportSelector,
        client: new NodeAgentPushClient,
    );
}

it('selects Agent push through its explicit transport selector', function (): void {
    $transport = node_agent_push_dispatcher_test_subject()->select(
        node: node_agent_push_dispatcher_test_node(),
        envelope: NodeCommandEnvelope::nodeExecuting(
            'internal:executor:verify',
            supportsAgentPushTransport: true,
        ),
    );

    expect($transport)->toBe(NodeTransport::AgentPush);
});

it('maps Agent frames to one ordered remote shell result', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.6.0.41:9477/v1/commands' => Http::response([
            'transport' => 'agent-push',
            'operation_id' => 'agent-push-dispatcher-operation',
            'binary' => 'orbit',
            'status' => 'succeeded',
            'exit_code' => 0,
            'frames' => [
                ['type' => 'stdout', 'message' => 'first'],
                ['type' => 'stderr', 'message' => 'warning'],
                ['type' => 'stdout', 'message' => '-second'],
                ['type' => 'stdout', 'message' => ['ignored']],
                ['message' => 'also ignored'],
            ],
        ]),
    ]);

    $result = node_agent_push_dispatcher_test_subject()->execute(
        node: node_agent_push_dispatcher_test_node(),
        envelope: NodeCommandEnvelope::agentPushBinary(
            operationId: 'agent-push-dispatcher-operation',
            binary: 'orbit',
            argv: ['internal:executor:verify', '--json'],
        ),
        operationToken: Str::random(40),
    );

    expect($result->exitCode)
        ->toBe(0)
        ->and($result->stdout)
        ->toBe('first-second')
        ->and($result->stderr)
        ->toBe('warning')
        ->and($result->durationMs)
        ->toBeGreaterThanOrEqual(0);
});

it('maps a missing Agent exit code to failure', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.6.0.42:9477/v1/commands' => Http::response([
            'transport' => 'agent-push',
            'operation_id' => 'agent-push-dispatcher-missing-exit',
            'binary' => 'orbit',
            'status' => 'failed',
            'exit_code' => null,
            'frames' => [],
        ]),
    ]);

    $result = node_agent_push_dispatcher_test_subject()->execute(
        node: node_agent_push_dispatcher_test_node('10.6.0.42'),
        envelope: NodeCommandEnvelope::agentPushBinary(
            operationId: 'agent-push-dispatcher-missing-exit',
            binary: 'orbit',
            argv: ['internal:executor:verify', '--json'],
        ),
        operationToken: Str::random(40),
    );

    expect($result->exitCode)->toBe(1);
});

it('rejects streaming when the selected transport is not Agent push', function (): void {
    $node = Node::factory()->gateway()->create();

    expect(fn (): mixed => node_agent_push_dispatcher_test_subject()->stream(
        node: $node,
        envelope: NodeCommandEnvelope::agentPushBinary(
            operationId: 'agent-push-dispatcher-stream',
            binary: 'orbit',
            argv: ['internal:executor:verify', '--json'],
            stream: true,
        ),
        operationToken: Str::random(40),
        onOutput: static fn (string $output): null => null,
    ))
        ->toThrow(RuntimeException::class, 'agent-push streaming transport is unavailable');
});
