<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Solo\HttpSoloUpstreamClient;
use App\Services\Solo\SoloUpstreamTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('sends configured bearer tokens and normalizes solo success envelopes', function (): void {
    Http::fake([
        '127.0.0.1:24678/api/projects' => Http::response([
            'ok' => true,
            'requestId' => 'req-live',
            'data' => [
                'projects' => [
                    ['id' => 4, 'name' => 'orbit'],
                ],
            ],
        ]),
    ]);

    $node = create_solo_gateway_upstream_node();
    $token = bin2hex(random_bytes(16));

    $target = new SoloUpstreamTarget(
        node: $node,
        url: 'http://127.0.0.1:24678/api',
        identity: 'gateway',
        bearerToken: $token,
    );

    $response = app(HttpSoloUpstreamClient::class)->get($target, '/projects');

    expect($response->ok)
        ->toBeTrue()
        ->and($response->data['projects'][0]['name'] ?? null)
        ->toBe('orbit');

    Http::assertSent(
        fn (Request $request): bool => (
            $request->hasHeader('Authorization', "Bearer {$token}") && $request->hasHeader('X-Orbit-Node', 'gateway')
        ),
    );
});

it('normalizes solo error envelopes', function (): void {
    Http::fake([
        '127.0.0.1:24678/api/projects' => Http::response([
            'ok' => false,
            'requestId' => 'req-live',
            'error' => [
                'code' => 'unauthorized',
                'message' => 'Invalid or missing bearer token.',
                'details' => ['path' => '/api/projects'],
            ],
        ], 401),
    ]);

    $node = create_solo_gateway_upstream_node();
    $target = new SoloUpstreamTarget(
        node: $node,
        url: 'http://127.0.0.1:24678/api',
        identity: 'gateway',
    );

    $response = app(HttpSoloUpstreamClient::class)->get($target, '/projects');

    expect($response->ok)
        ->toBeFalse()
        ->and($response->error?->code)
        ->toBe('unauthorized')
        ->and($response->error?->message)
        ->toBe('Invalid or missing bearer token.')
        ->and($response->error?->meta['path'] ?? null)
        ->toBe('/api/projects')
        ->and($response->error?->status)
        ->toBe(401);
});

it('maps solo discovery agent tools to the orbit tools key', function (): void {
    Http::fake([
        '127.0.0.1:24678/api/discovery' => Http::response([
            'ok' => true,
            'requestId' => 'req-discovery',
            'data' => [
                'schemaVersion' => 3,
                'agentTools' => [
                    ['name' => 'Codex', 'toolType' => 'codex'],
                ],
            ],
        ]),
    ]);

    $node = create_solo_gateway_upstream_node();
    $target = new SoloUpstreamTarget(
        node: $node,
        url: 'http://127.0.0.1:24678/api',
        identity: 'gateway',
    );

    $response = app(HttpSoloUpstreamClient::class)->get($target, '/discovery');

    expect($response->ok)
        ->toBeTrue()
        ->and($response->data['tools'][0]['toolType'] ?? null)
        ->toBe('codex')
        ->and($response->data['agentTools'][0]['name'] ?? null)
        ->toBe('Codex');
});

it('maps solo todo delete receipts to the orbit todo key', function (): void {
    Http::fake([
        '127.0.0.1:24678/api/projects/4/todos/175' => Http::response([
            'ok' => true,
            'requestId' => 'req-delete',
            'data' => [
                'id' => 175,
                'projectId' => 4,
                'deleted' => true,
                'affectedTodoIds' => [],
            ],
        ]),
    ]);

    $node = create_solo_gateway_upstream_node();
    $target = new SoloUpstreamTarget(
        node: $node,
        url: 'http://127.0.0.1:24678/api',
        identity: 'gateway',
    );

    $response = app(HttpSoloUpstreamClient::class)->delete($target, '/projects/4/todos/175', []);

    expect($response->ok)
        ->toBeTrue()
        ->and($response->data['todo']['id'] ?? null)
        ->toBe(175)
        ->and($response->data['todo']['deleted'] ?? null)
        ->toBeTrue();
});

it('does not treat a malformed remote solo envelope as a successful upstream response', function (string $stdout): void {
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-dev-solo',
            'wireguard_address' => '10.44.0.91',
        ]);
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.91:9477/v1/commands' => Http::response([
            'transport' => 'agent-push',
            'operation_id' => 'solo-upstream-request',
            'binary' => 'orbit',
            'status' => 'succeeded',
            'exit_code' => 0,
            'frames' => [
                [
                    'type' => 'stdout',
                    'message' => $stdout,
                ],
                [
                    'type' => 'exit',
                    'message' => '0',
                ],
            ],
        ]),
    ]);

    $response = app(HttpSoloUpstreamClient::class)->get(
        new SoloUpstreamTarget(
            node: $node,
            url: 'http://127.0.0.1:24678/api',
            identity: 'app-dev-solo',
        ),
        '/projects',
    );

    expect($response->ok)
        ->toBeFalse()
        ->and($response->error?->code)
        ->toBe('solo_upstream_error')
        ->and($response->error?->status)
        ->toBe(502);
})->with([
    'empty output' => '',
    'malformed JSON' => '{"success":',
    'missing success.data' => '{"success":{"meta":[]}}',
    'invalid success.data' => '{"success":{"data":"invalid","meta":[]}}',
]);

function create_solo_gateway_upstream_node(): Node
{
    $node = Node::factory()->create(['name' => 'gateway']);

    NodeRoleAssignment::factory()->for($node)->create([
        'role' => NodeRoleName::Gateway->value,
    ]);

    return $node;
}
