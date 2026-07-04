<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('runs an agent-push noop proof against a named node and returns structured json', function (): void {
    Http::preventStrayRequests();
    $operationToken = agent_push_proof_test_operation_token();

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

    Node::factory()->create([
        'name' => 'mini',
        'host' => 'mini.local',
        'wireguard_address' => '10.6.0.23',
        'status' => NodeStatus::Active,
        'orbit_agent_capable' => true,
    ]);

    $exitCode = Artisan::call('orbit:internal:agent-push-proof', [
        'node' => 'mini',
        '--operation-token' => $operationToken,
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'node' => 'mini',
        'transport' => 'agent-push',
        'status' => 'succeeded',
        'command_id' => 'orbit.agent.noop',
    ]);

    Http::assertSent(function (Request $request): bool {
        return (
            $request->url() === 'http://10.6.0.23:9477/v1/commands'
            && $request->hasHeader('Authorization')
            && $request['command_id'] === 'orbit.agent.noop'
        );
    });
});

it('fails before transport when agent-push selection is unavailable', function (): void {
    Http::preventStrayRequests();

    Node::factory()->create([
        'name' => 'mini',
        'host' => 'mini.local',
        'wireguard_address' => '10.6.0.23',
        'status' => NodeStatus::Active,
        'orbit_agent_capable' => false,
    ]);

    $exitCode = Artisan::call('orbit:internal:agent-push-proof', [
        'node' => 'mini',
        '--operation-token' => agent_push_proof_test_operation_token(),
        '--json' => true,
    ]);

    expect($exitCode)->toBe(1);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'node' => 'mini',
        'transport' => 'transitional-ssh-fallback',
        'status' => 'failed',
        'command_id' => 'orbit.agent.noop',
    ]);
});

function agent_push_proof_test_operation_token(): string
{
    return 'op_'.Str::random(32);
}
