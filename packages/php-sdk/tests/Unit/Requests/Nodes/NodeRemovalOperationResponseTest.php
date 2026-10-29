<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('transports removal flags and its idempotency key and returns the Operation', function (): void {
    $request = new RemoveNodeRequest(7, true, true, 'removal-proof');
    $mock = new MockClient([RemoveNodeRequest::class => MockResponse::make([
        'data' => ['id' => 123, 'kind' => 'node.remove', 'target_type' => 'node', 'target_id' => 7,
            'status' => 'queued', 'sequence' => 1, 'input' => [], 'steps' => []],
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ], 202)]);
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient($mock);

    $operation = $connector->send($request)->dto();

    expect($operation)->toBeInstanceOf(OperationResponse::class);
    expect($operation->id)->toBe(123);
    expect($operation->requestId)->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844');
    $mock->assertSent(fn ($pending): bool => $pending->headers()->get('Idempotency-Key') === 'removal-proof'
        && $pending->getMethod()->value === 'DELETE' && $pending->resolveEndpoint() === '/api/v1/nodes/7'
        && $pending->body()->all() === ['force' => true, 'offline' => true]);
});
