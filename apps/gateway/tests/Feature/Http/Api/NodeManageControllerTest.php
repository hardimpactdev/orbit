<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const NODE_MANAGE_CALLER_WG_IP = '10.44.0.24';

it('persists management metadata after Agent-push verification', function (): void {
    Http::fake([
        'http://10.44.0.24:9477/v1/commands' => node_manage_agent_response(),
    ]);
    $node = Node::factory()
        ->operator()
        ->create([
            'name' => 'mini',
            'host' => NODE_MANAGE_CALLER_WG_IP,
            'wireguard_address' => NODE_MANAGE_CALLER_WG_IP,
            'status' => 'active',
            'managed' => false,
        ]);

    $response = $this->call(
        'POST',
        '/api/nodes/self/manage',
        ['user' => 'nicky', 'platform' => 'macos_15-5'],
        server: ['REMOTE_ADDR' => NODE_MANAGE_CALLER_WG_IP],
    );

    $response
        ->assertOk()
        ->assertJsonPath('success.data.management.node', 'mini')
        ->assertJsonPath('success.data.management.managed', true)
        ->assertJsonPath('success.data.management.agent_verified', true);

    expect($node->fresh())
        ->user->toBe('nicky')
        ->platform->toBe('macos_15-5')
        ->managed->toBeTrue()
        ->host_key_fingerprint->toBeNull();
});

it('retains management intent when Agent push is unavailable', function (): void {
    Http::fake([
        'http://10.44.0.24:9477/v1/commands' => Http::response(['error' => 'unreachable'], 503),
    ]);
    $node = Node::factory()
        ->operator()
        ->create([
            'name' => 'mini',
            'user' => null,
            'platform' => null,
            'wireguard_address' => NODE_MANAGE_CALLER_WG_IP,
            'status' => 'active',
            'managed' => false,
        ]);

    $this
        ->call(
            'POST',
            '/api/nodes/self/manage',
            ['user' => 'nicky', 'platform' => 'macos_15-5'],
            server: ['REMOTE_ADDR' => NODE_MANAGE_CALLER_WG_IP],
        )
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'node.agent_unreachable');

    expect($node->fresh())
        ->user->toBe('nicky')
        ->platform->toBe('macos_15-5')
        ->managed->toBeTrue();
});

it('rejects role-bearing callers before management side effects', function (): void {
    createTestGatewayNode([
        'name' => 'gateway-1',
        'host' => NODE_MANAGE_CALLER_WG_IP,
        'wireguard_address' => NODE_MANAGE_CALLER_WG_IP,
        'status' => 'active',
    ]);

    $this
        ->call(
            'POST',
            '/api/nodes/self/manage',
            ['user' => 'orbit', 'platform' => 'ubuntu_24-04'],
            server: ['REMOTE_ADDR' => NODE_MANAGE_CALLER_WG_IP],
        )
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'node.not_operator');

    Http::assertNothingSent();
});

function node_manage_agent_response(): mixed
{
    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => 'node.manage.agent-probe',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [['type' => 'exit', 'message' => '0']],
    ]);
}
