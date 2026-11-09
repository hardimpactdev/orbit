<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\UpdateNodeRequest;
use Orbit\Sdk\Responses\Nodes\AppsSettings;
use Orbit\Sdk\Responses\Nodes\NodeUpdateResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('returns the admitted Operation and preserves the idempotency key', function (): void {
    $request = new UpdateNodeRequest(2, hasApps: true, apps: new AppsSettings('/srv/apps'), idempotencyKey: 'settings-proof');
    $mock = new MockClient([UpdateNodeRequest::class => MockResponse::make([
        'data' => ['id' => 7, 'kind' => 'node.update', 'target_type' => 'node', 'target_id' => 2,
            'status' => 'queued', 'sequence' => 1, 'input' => [], 'steps' => []],
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ], 202)]);
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient($mock);

    $response = $connector->send($request)->dto();

    expect($response)->toBeInstanceOf(NodeUpdateResponse::class);
    expect($response->result)->toBeInstanceOf(OperationResponse::class);
    expect($response->result->kind)->toBe('node.update');
    $mock->assertSent(fn ($pending): bool => $pending->headers()->get('Idempotency-Key') === 'settings-proof'
        && $pending->body()->all() === ['apps' => ['path' => '/srv/apps']]);
});

it('preserves the unchanged reason beside the Node detail', function (): void {
    $request = new UpdateNodeRequest(2, name: 'tablet');
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient(new MockClient([UpdateNodeRequest::class => MockResponse::make([
        'data' => ['id' => 2, 'name' => 'tablet', 'status' => 'active', 'skipped' => 'node.unchanged'],
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ])]));
    $response = $connector->send($request)->dto();
    expect($response)->toBeInstanceOf(NodeUpdateResponse::class)
        ->and($response->skipped)->toBe('node.unchanged')
        ->and($response->result->id)->toBe(2);
});
