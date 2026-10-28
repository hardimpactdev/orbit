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

    $response = $connector->send($request)->dto();

    expect($response)->toBeInstanceOf(OperationResponse::class);
    expect($response->kind)->toBe('node.converge');
    $mock->assertSent(fn ($pending): bool => $pending->headers()->get('Idempotency-Key') === 'converge-proof'
        && $pending->body()->all() === ['force' => true]);
});
