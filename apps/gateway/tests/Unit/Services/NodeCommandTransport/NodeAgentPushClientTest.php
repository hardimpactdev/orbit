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

it('pushes an allowlisted binary argv envelope to the target node agent listener', function (): void {
    Http::preventStrayRequests();
    $operationToken = agent_push_client_test_operation_token();

    Http::fake([
        'http://10.6.0.23:9477/v1/commands' => Http::response([
            'transport' => 'agent-push',
            'operation_id' => 'op_gateway_test_123',
            'binary' => 'orbit',
            'status' => 'succeeded',
            'exit_code' => 0,
            'frames' => [
                [
                    'type' => 'stdout',
                    'message' => '{"version":"0.1.0"}',
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
        envelope: NodeCommandEnvelope::agentPushBinary(
            operationId: 'op_gateway_test_123',
            binary: 'orbit',
            argv: ['app:list', '--json'],
        ),
        operationToken: $operationToken,
    );

    expect($result->transport)->toBe('agent-push');
    expect($result->operationId)->toBe('op_gateway_test_123');
    expect($result->binary)->toBe('orbit');
    expect($result->status)->toBe('succeeded');
    expect($result->exitCode)->toBe(0);
    expect($result->frames)->toHaveCount(1);

    Http::assertSent(
        fn (Request $request): bool => (
            $request->method() === 'POST'
            && $request->url() === 'http://10.6.0.23:9477/v1/commands'
            && $request->hasHeader('Authorization')
            && $request['operation_id'] === 'op_gateway_test_123'
            && $request['binary'] === 'orbit'
            && $request['argv'] === ['app:list', '--json']
            && $request['operation_token'] !== null
            && $request['timeout_seconds'] === 30
            && $request['stream'] === true
        ),
    );
});

it('accepts nullable exit codes from the target node agent listener', function (): void {
    Http::preventStrayRequests();

    Http::fake([
        'http://10.6.0.23:9477/v1/commands' => Http::response([
            'transport' => 'agent-push',
            'operation_id' => 'op_gateway_test_456',
            'binary' => 'orbit',
            'status' => 'failed',
            'exit_code' => null,
            'frames' => [
                [
                    'type' => 'stderr',
                    'message' => 'binary execution timed out after 30 seconds',
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
        envelope: NodeCommandEnvelope::agentPushBinary(
            operationId: 'op_gateway_test_456',
            binary: 'orbit',
            argv: ['app:list', '--json'],
        ),
        operationToken: agent_push_client_test_operation_token(),
    );

    expect($result->status)->toBe('failed');
    expect($result->exitCode)->toBeNull();
    expect($result->frames)->toHaveCount(1);
});

it('uses the envelope command timeout with a small HTTP buffer', function (): void {
    $method = new ReflectionMethod(NodeAgentPushClient::class, 'requestTimeoutSeconds');
    $method->setAccessible(true);

    $shortEnvelope = NodeCommandEnvelope::agentPushBinary(
        operationId: 'op_gateway_test_short_timeout',
        binary: 'orbit',
        argv: ['app:list', '--json'],
        timeoutSeconds: 3,
    );
    $longEnvelope = NodeCommandEnvelope::agentPushBinary(
        operationId: 'op_gateway_test_long_timeout',
        binary: 'orbit',
        argv: ['internal:app-introspect:probe', '--json'],
        timeoutSeconds: 45,
    );

    expect($method->invoke(new NodeAgentPushClient, $shortEnvelope))
        ->toBe(10)
        ->and($method->invoke(new NodeAgentPushClient, $longEnvelope))
        ->toBe(50);
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
