<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\ConvergeNodeWireGuardHubRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('transports only the Node selector and optional idempotency key for hub convergence', function (?string $key): void {
    $request = new ConvergeNodeWireGuardHubRequest(13, idempotencyKey: $key);
    $mock = new MockClient([ConvergeNodeWireGuardHubRequest::class => MockResponse::make([
        'data' => ['id' => 7, 'kind' => 'node.wireguard_hub_converge', 'target_type' => 'node', 'target_id' => 13,
            'status' => 'queued', 'sequence' => 1, 'input' => []],
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ], 202)]);
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient($mock);
    $response = $connector->send($request)->dto();
    expect($response)->toBeInstanceOf(OperationResponse::class);
    expect($response->kind)->toBe('node.wireguard_hub_converge');
    $mock->assertSent(fn ($pending): bool => $pending->getMethod() === Method::POST
        && $pending->resolveEndpoint() === '/api/v1/nodes/13/wireguard-hub/converge'
        && $pending->headers()->get('Idempotency-Key') === $key);
})->with([null, 'hub-proof']);
