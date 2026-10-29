<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\ConvergeNodeRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('returns the admitted Operation and preserves the converge idempotency key', function (): void {
    $request = new ConvergeNodeRequest(2, true, idempotencyKey: 'converge-proof');
    $mock = new MockClient([ConvergeNodeRequest::class => MockResponse::make([
        'data' => ['id' => 7, 'kind' => 'node.converge', 'target_type' => 'node', 'target_id' => 2,
            'status' => 'queued', 'sequence' => 1, 'input' => [], 'steps' => []],
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ], 202)]);
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient($mock);

    $response = $connector->send($request)->dto()->result;

    expect($response)->toBeInstanceOf(OperationResponse::class);
    expect($response->kind)->toBe('node.converge');
    $mock->assertSent(fn ($pending): bool => $pending->headers()->get('Idempotency-Key') === 'converge-proof'
        && $pending->body()->all() === ['force' => true]);
});

it('reads the synchronous executor footprint response without an Operation', function (): void {
    $request = new ConvergeNodeRequest(17, true);
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient(new MockClient([ConvergeNodeRequest::class => MockResponse::make([
        'data' => ['node_id' => 17, 'node' => 'services', 'artifacts' => ['agent' => 'applied'], 'digest' => str_repeat('a', 64), 'changed' => true],
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ], 200)]));
    $result = $connector->send($request)->dto()->result;
    expect($result)->toBeInstanceOf(\Orbit\Sdk\Responses\Fleet\NodeFootprintResponse::class)
        ->and($result->nodeId)->toBe(17)->and($result->changed)->toBeTrue();
});
