<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\WireGuardPeer;
use App\Services\Nodes\NodeIdentityArtifactProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {});

function node_identity_public_key_agent_response(string $publicKey): mixed
{
    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => 'node-identity.wireguard-public-key',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => [
                            'public_key' => $publicKey,
                        ],
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'type' => 'exit',
                'message' => '0',
            ],
        ],
    ]);
}

function node_identity_public_key_agent_failure(string $stderr): mixed
{
    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => 'node-identity.wireguard-public-key',
        'binary' => 'orbit',
        'status' => 'failed',
        'exit_code' => 1,
        'frames' => [
            [
                'type' => 'stderr',
                'message' => $stderr,
            ],
            [
                'type' => 'exit',
                'message' => '1',
            ],
        ],
    ]);
}

function node_identity_public_key_request_matches(Request $request, string $url): bool
{
    $argv = $request['argv'] ?? null;

    if (! is_array($argv)) {
        return false;
    }

    return (
        $request->url() === $url
        && $request['binary'] === 'orbit'
        && agentPushRequestOperationIdMatchesToken($request)
        && $request['timeout_seconds'] === 15
        && ($argv[0] ?? null) === 'internal:wireguard-interface-public-key:read'
        && is_string($argv[1] ?? null)
        && str_starts_with($argv[1], '--operation-token=')
        && ($argv[2] ?? null) === '--json'
    );
}

it('reads non-secret node identity facts from the selected host', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.88:9477/v1/commands' => node_identity_public_key_agent_response('interface-public-key'),
    ]);
    Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway',
            'orbit_path' => '/home/orbit/orbit',
        ]);
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-1',
            'orbit_path' => '/home/orbit/orbit',
            'wireguard_address' => '10.44.0.88',
        ]);
    assert($node instanceof Node);

    WireGuardPeer::factory()->create([
        'node_id' => $node->id,
        'public_key' => 'interface-public-key',
        'private_key' => 'private-key',
    ]);

    $artifact = new NodeIdentityArtifactProbe()->read($node);

    expect($artifact->name)
        ->toBe('app-1')
        ->and($artifact->role)
        ->toBe('app-dev')
        ->and($artifact->localRole)
        ->toBe('app-dev')
        ->and($artifact->status)
        ->toBe('active')
        ->and($artifact->platform)
        ->toBe('ubuntu_24-04')
        ->and($artifact->wireguardAddress)
        ->toBe('10.44.0.88')
        ->and($artifact->registryPublicKey)
        ->toBe('interface-public-key')
        ->and($artifact->interfacePublicKey)
        ->toBe('interface-public-key');
    Http::assertSent(
        fn (Request $request): bool => node_identity_public_key_request_matches(
            request: $request,
            url: 'http://10.44.0.88:9477/v1/commands',
        ),
    );
});

it('throws when host identity artifact reading fails', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.89:9477/v1/commands' => node_identity_public_key_agent_failure('missing app'),
    ]);
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-1',
            'wireguard_address' => '10.44.0.89',
        ]);
    assert($node instanceof Node);

    expect(fn () => new NodeIdentityArtifactProbe()->read($node))
        ->toThrow(RuntimeException::class, 'Failed to read node WireGuard interface public key: missing app');
});

it('returns the target public key when no active registry peer matches', function (): void {
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-1',
            'wireguard_address' => '10.44.0.90',
        ]);
    assert($node instanceof Node);

    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.90:9477/v1/commands' => node_identity_public_key_agent_response('interface-public-key'),
    ]);

    $artifact = new NodeIdentityArtifactProbe()->read($node);

    expect($artifact->name)
        ->toBe('app-1')
        ->and($artifact->registryPublicKey)
        ->toBeNull()
        ->and($artifact->interfacePublicKey)
        ->toBe('interface-public-key');
});
