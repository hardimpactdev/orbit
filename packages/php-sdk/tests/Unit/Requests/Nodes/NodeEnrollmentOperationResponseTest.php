<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\AddNodeRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('returns the admitted enrollment Operation and preserves its idempotency key', function (): void {
    $request = new AddNodeRequest('candidate', '192.0.2.73', roles: ['app-prod'], idempotencyKey: 'enroll-candidate');
    $mock = new MockClient([AddNodeRequest::class => MockResponse::make([
        'data' => ['id' => 7, 'kind' => 'node.add', 'target_type' => 'node', 'target_id' => 73,
            'status' => 'queued', 'sequence' => 1, 'input' => [], 'steps' => []],
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ], 202)]);
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient($mock);

    $response = $connector->send($request)->dto();

    expect($response)->toBeInstanceOf(OperationResponse::class)
        ->and($response->kind)->toBe('node.add')
        ->and($response->targetId)->toBe(73)
        ->and($response->status)->toBe('queued');
    $mock->assertSent(fn ($pending): bool => $pending->headers()->get('Idempotency-Key') === 'enroll-candidate'
        && $pending->body()->all()['roles'] === ['app-prod']);
});

it('refuses the retired synchronous Node response', function (): void {
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient(new MockClient([AddNodeRequest::class => MockResponse::make([
        'data' => ['id' => 73, 'name' => 'candidate', 'status' => 'active', 'roles' => []],
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ], 201)]));

    expect(fn () => $connector->send(new AddNodeRequest('candidate', '192.0.2.73'))->dto())
        ->toThrow(InvalidArgumentException::class, 'Gateway response contains an invalid operation.');
});
