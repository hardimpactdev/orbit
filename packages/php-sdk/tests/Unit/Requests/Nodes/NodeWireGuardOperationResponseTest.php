<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\SetNodeWireGuardKeyRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('returns the hub publication Operation and keeps the public key and idempotency header', function (): void {
    $key = base64_encode(str_repeat('a', 32));
    $request = new SetNodeWireGuardKeyRequest(2, $key, idempotencyKey: 'wg-proof');
    $mock = new MockClient([SetNodeWireGuardKeyRequest::class => MockResponse::make([
        'data' => ['id' => 7, 'kind' => 'node.wireguard_key', 'target_type' => 'node', 'target_id' => 2,
            'status' => 'queued', 'sequence' => 1, 'input' => [], 'steps' => []],
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ], 202)]);
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient($mock);
    expect($connector->send($request)->dto())->toBeInstanceOf(OperationResponse::class);
    $mock->assertSent(fn ($pending): bool => $pending->headers()->get('Idempotency-Key') === 'wg-proof'
        && $pending->body()->all() === ['wireguard_public_key' => $key]);
});
