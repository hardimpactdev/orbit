<?php

declare(strict_types=1);

use App\Models\Node;
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

    $node = Node::factory()->create(['name' => 'gateway']);
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
            $request->hasHeader('Authorization', "Bearer {$token}")
            && $request->hasHeader('X-Orbit-Node', 'gateway')
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

    $node = Node::factory()->create(['name' => 'gateway']);
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

    $node = Node::factory()->create(['name' => 'gateway']);
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

    $node = Node::factory()->create(['name' => 'gateway']);
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
