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

it('runs an agent-push binary argv proof against a named node and returns structured json', function (): void {
    Http::preventStrayRequests();
    $operationToken = agent_push_proof_test_operation_token();

    Http::fake([
        'http://10.6.0.23:9477/v1/commands' => Http::response([
            'transport' => 'agent-push',
            'operation_id' => 'op_agent_push_proof_123',
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

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'node' => 'mini',
        'transport' => 'agent-push',
        'status' => 'succeeded',
        'binary' => 'orbit',
        'exit_code' => 0,
    ]);
    expect($payload['operation_id'])->toBeString()->toStartWith('op_');
    expect($payload['frames'])->toHaveCount(1);

    Http::assertSent(
        fn (Request $request): bool => (
            $request->url() === 'http://10.6.0.23:9477/v1/commands'
            && $request->hasHeader('Authorization')
            && $request['binary'] === 'orbit'
            && $request['argv'] === ['version', '--json']
            && is_string($request['operation_id'])
            && str_starts_with($request['operation_id'], 'op_')
            && $request['timeout_seconds'] === 30
            && $request['stream'] === true
        ),
    );
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

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'node' => 'mini',
        'transport' => 'transitional-ssh-fallback',
        'status' => 'failed',
        'binary' => 'orbit',
    ]);
});

function agent_push_proof_test_operation_token(): string
{
    return 'op_'.Str::random(32);
}
