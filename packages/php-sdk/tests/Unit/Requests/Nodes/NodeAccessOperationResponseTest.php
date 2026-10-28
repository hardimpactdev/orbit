<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\RemoveNodeAccessRequest;
use Orbit\Sdk\Responses\Nodes\NodeAccessMutationResponse;
use Orbit\Sdk\Responses\Nodes\RemovedNodeAccessResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('transports state-only access removals and Operations with the same idempotency header', function (bool $async): void {
    $request = new RemoveNodeAccessRequest(2, 3, idempotencyKey: 'access-proof');
    $data = $async ? ['id' => 7, 'kind' => 'node.access_remove', 'target_type' => 'node', 'target_id' => 3,
        'status' => 'queued', 'sequence' => 1, 'input' => ['access' => ['self_lockout' => true]], 'steps' => []]
        : ['consumer_node' => ['id' => 2, 'name' => 'operator'], 'serving_node' => ['id' => 3, 'name' => 'serving'],
            'already_absent' => false, 'self_lockout' => false];
    $mock = new MockClient([RemoveNodeAccessRequest::class => MockResponse::make([
        'data' => $data, 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ], $async ? 202 : 200)]);
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient($mock);
    $response = $connector->send($request)->dto();
    expect($response)->toBeInstanceOf(NodeAccessMutationResponse::class)
        ->and($response->result)->toBeInstanceOf($async ? OperationResponse::class : RemovedNodeAccessResponse::class);
    $mock->assertSent(fn ($pending): bool => $pending->headers()->get('Idempotency-Key') === 'access-proof');
})->with([false, true]);
