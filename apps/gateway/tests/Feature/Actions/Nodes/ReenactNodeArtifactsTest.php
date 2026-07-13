<?php

declare(strict_types=1);

use App\Actions\Nodes\ReenactNodeArtifacts;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {});

it('rotates wireguard endpoints when gateway endpoint changes', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.6.0.10:9477/v1/commands' => reenact_node_artifacts_agent_response(exitCode: 0),
    ]);

    /** @var Node $node */
    $node = Node::factory()->create([
        'name' => 'app-1',
        'host' => '192.0.2.10',
        'wireguard_address' => '10.6.0.10',
        'managed' => true,
        'gateway_endpoint' => '10.3.0.2',
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAATEST',
        'host_key_fingerprint' => 'SHA256:test',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-prod',
        'status' => 'active',
    ]);

    $warnings = app(ReenactNodeArtifacts::class)->handle($node, ['gateway_endpoint']);

    expect($warnings)
        ->toBe([]);

    Http::assertSent(fn (Request $request): bool => reenact_node_artifacts_request_matches($request));
});

it('returns a warning when wireguard endpoint rotation fails', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.6.0.10:9477/v1/commands' => reenact_node_artifacts_agent_response(exitCode: 1),
    ]);

    /** @var Node $node */
    $node = Node::factory()->create([
        'name' => 'app-1',
        'wireguard_address' => '10.6.0.10',
        'managed' => true,
        'gateway_endpoint' => '10.3.0.2',
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAATEST',
        'host_key_fingerprint' => 'SHA256:test',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-prod',
        'status' => 'active',
    ]);

    $warnings = app(ReenactNodeArtifacts::class)->handle($node, ['gateway_endpoint']);

    expect($warnings)->toBe([[
        'code' => 'node.artifact_enactment_failed',
        'message' => 'Node artifact re-enactment failed after intent update.',
        'family' => 'node',
        'next_command' => 'doctor --family=node --restore',
    ]]);
});

it('does not rotate the local gateway node when its advertised endpoint metadata changes', function (): void {
    Http::preventStrayRequests();
    Http::fake();

    /** @var Node $node */
    $node = Node::factory()->create([
        'name' => 'gateway',
        'wireguard_address' => '10.6.0.2',
        'gateway_endpoint' => '188.245.156.201',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    $warnings = app(ReenactNodeArtifacts::class)->handle($node, ['gateway_endpoint']);

    expect($warnings)->toBe([]);
    Http::assertNothingSent();
});

function reenact_node_artifacts_agent_response(int $exitCode): mixed
{
    $payload = $exitCode === 0
        ? ['success' => ['data' => ['endpoint' => '10.3.0.2:51820'], 'meta' => []]]
        : ['error' => ['code' => 'wireguard_config_missing', 'message' => 'missing wireguard config', 'meta' => []]];

    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => 'node.gateway_endpoint.rotate',
        'binary' => 'orbit',
        'status' => $exitCode === 0 ? 'succeeded' : 'failed',
        'exit_code' => $exitCode,
        'frames' => [
            [
                'type' => $exitCode === 0 ? 'stdout' : 'stderr',
                'message' => json_encode($payload, JSON_THROW_ON_ERROR),
            ],
            [
                'type' => 'exit',
                'message' => (string) $exitCode,
            ],
        ],
    ]);
}

function reenact_node_artifacts_request_matches(Request $request): bool
{
    /** @var mixed $argv */
    $argv = $request['argv'];

    return (
        is_array($argv)
        && $request->url() === 'http://10.6.0.10:9477/v1/commands'
        && $request['binary'] === 'orbit'
        && ($argv[0] ?? null) === 'internal:wireguard-endpoint:rotate'
        && ($argv[1] ?? null) === '10.3.0.2:51820'
        && is_string($argv[2] ?? null)
        && str_starts_with($argv[2], '--operation-token=')
        && ($argv[3] ?? null) === '--json'
        && agentPushRequestOperationIdMatchesToken($request)
    );
}
