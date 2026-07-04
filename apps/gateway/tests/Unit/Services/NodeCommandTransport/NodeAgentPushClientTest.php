<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Services\NodeCommandTransport\NodeAgentPushClient;
use App\Services\NodeCommandTransport\NodeCommandEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('pushes an allowlisted noop envelope to the target node agent listener', function (): void {
    Http::preventStrayRequests();
    $operationToken = agent_push_client_test_operation_token();

    Http::fake([
        'http://10.6.0.23:9477/v1/commands' => Http::response([
            'transport' => 'agent-push',
            'command_id' => 'orbit.agent.noop',
            'status' => 'succeeded',
            'frames' => [
                [
                    'type' => 'status',
                    'message' => 'noop accepted',
                ],
            ],
        ]),
    ]);

    $node = Node::factory()->create([
        'name' => 'mini',
        'host' => 'mini.local',
        'wireguard_address' => '10.6.0.23',
        'status' => NodeStatus::Active,
        'orbit_agent_capable' => true,
    ]);

    $result = new NodeAgentPushClient()->execute(
        node: $node,
        envelope: NodeCommandEnvelope::agentPushNoop(),
        operationToken: $operationToken,
    );

    expect($result->transport)->toBe('agent-push');
    expect($result->commandId)->toBe('orbit.agent.noop');
    expect($result->status)->toBe('succeeded');
    expect($result->frames)->toHaveCount(1);

    Http::assertSent(function (Request $request): bool {
        return (
            $request->method() === 'POST'
            && $request->url() === 'http://10.6.0.23:9477/v1/commands'
            && $request->hasHeader('Authorization')
            && $request['command_id'] === 'orbit.agent.noop'
            && str_contains($request->body(), '"payload":{}')
        );
    });
});

it('refuses to push envelopes that are not allowlisted for agent push', function (): void {
    Http::preventStrayRequests();

    $node = Node::factory()->create([
        'name' => 'mini',
        'host' => 'mini.local',
        'wireguard_address' => '10.6.0.23',
        'status' => NodeStatus::Active,
        'orbit_agent_capable' => true,
    ]);

    new NodeAgentPushClient()->execute(
        node: $node,
        envelope: NodeCommandEnvelope::nodeExecuting('internal:agent-unsupported'),
        operationToken: agent_push_client_test_operation_token(),
    );
})->throws(
    InvalidArgumentException::class,
    'Only allowlisted agent-push envelopes can be sent to the Orbit Agent listener.',
);

function agent_push_client_test_operation_token(): string
{
    return 'op_'.Str::random(32);
}
