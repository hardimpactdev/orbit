<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Nodes\OperatorNodeManagementException;
use App\Services\Nodes\OperatorNodeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('fails when the operator node has no WireGuard address', function (): void {
    $node = Node::factory()
        ->operator()
        ->create([
            'name' => 'mini',
            'wireguard_address' => null,
            'status' => 'active',
        ]);

    expect(fn () => app(OperatorNodeManager::class)->manage($node, 'nicky', 'macos_15-5'))
        ->toThrow(function (OperatorNodeManagementException $exception): void {
            expect($exception->errorCode)->toBe('node.wireguard_address_missing');
        });
});

it('opts a roleless operator into management after an Agent-push probe succeeds', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.24:9477/v1/commands' => operator_node_agent_response(),
    ]);
    $node = Node::factory()
        ->operator()
        ->create([
            'name' => 'mini',
            'wireguard_address' => '10.44.0.24',
            'status' => 'active',
            'managed' => false,
        ]);

    $result = app(OperatorNodeManager::class)->manage($node, 'nicky', 'macos_15-5');

    expect($result)
        ->toMatchArray([
            'node' => 'mini',
            'user' => 'nicky',
            'platform' => 'macos_15-5',
            'managed' => true,
            'agent_verified' => true,
        ])
        ->and($node->fresh()->managed)
        ->toBeTrue();

    Http::assertSent(
        fn (Request $request): bool => $request->url() === 'http://10.44.0.24:9477/v1/commands'
        && $request['argv'][0] === 'internal:agent-runtime:probe'
        && ! $request->hasHeader('X-Orbit-Node-Transport-Preference'),
    );
});

it('retains management intent when Agent push fails', function (): void {
    Http::fake([
        'http://10.44.0.24:9477/v1/commands' => Http::response(['error' => 'unreachable'], 503),
    ]);
    $node = Node::factory()
        ->operator()
        ->create([
            'name' => 'mini',
            'user' => null,
            'platform' => null,
            'wireguard_address' => '10.44.0.24',
            'status' => 'active',
            'managed' => false,
        ]);

    expect(fn () => app(OperatorNodeManager::class)->manage($node, 'nicky', 'macos_15-5'))
        ->toThrow(function (OperatorNodeManagementException $exception) use ($node): void {
            expect($exception->errorCode)
                ->toBe('node.agent_unreachable')
                ->and($node->fresh()->user)
                ->toBe('nicky')
                ->and($node->fresh()->platform)
                ->toBe('macos_15-5')
                ->and($node->fresh()->managed)
                ->toBeTrue();
        });
});

function operator_node_agent_response(): mixed
{
    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => 'node.manage.agent-probe',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            ['type' => 'exit', 'message' => '0'],
        ],
    ]);
}
