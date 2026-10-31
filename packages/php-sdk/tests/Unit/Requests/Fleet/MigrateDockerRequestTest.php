<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Fleet\MigrateDockerRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('returns the admitted Operation and preserves the Docker idempotency key', function (): void {
    $request = new MigrateDockerRequest(2, 'migrate', confirm: true, fallback: true, recipe: 'sha256:recipe', idempotencyKey: 'docker-proof');
    $mock = new MockClient([MigrateDockerRequest::class => MockResponse::make([
        'data' => ['id' => 7, 'kind' => 'fleet.docker_migrate', 'target_type' => 'node', 'target_id' => 2,
            'status' => 'queued', 'sequence' => 1, 'input' => [], 'steps' => []],
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ], 202)]);
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient($mock);

    $response = $connector->send($request)->dto();

    expect($response)->toBeInstanceOf(OperationResponse::class);
    expect($response->kind)->toBe('fleet.docker_migrate');
    $mock->assertSent(fn ($pending): bool => $pending->headers()->get('Idempotency-Key') === 'docker-proof'
        && $pending->body()->all() === ['mode' => 'migrate', 'confirm' => true, 'fallback' => true, 'recipe' => 'sha256:recipe']);
});

it('refuses a retired synchronous Docker response', function (): void {
    $request = new MigrateDockerRequest(17, 'plan');
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient(new MockClient([MigrateDockerRequest::class => MockResponse::make([
        'data' => ['node_id' => 17, 'node' => 'services', 'artifacts' => ['agent' => 'applied'], 'digest' => str_repeat('a', 64), 'changed' => true],
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ], 200)]));
    expect(fn () => $connector->send($request)->dto())
        ->toThrow(InvalidArgumentException::class, 'Gateway response contains an invalid operation.');
});
