<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\ConvergeNodeRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Response;

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
        && $pending->body()->all() === ['base' => true]);
});

describe('converge scope transport', function (): void {
    it('preserves omitted and explicit role selections without adding policy', function (?array $roles, array $expected): void {
        $request = new ConvergeNodeRequest(7, roles: $roles);
        $mock = new MockClient([ConvergeNodeRequest::class => MockResponse::make([], 202)]);
        $connector = new GatewayConnector('https://gateway.example.test');
        $connector->withMockClient($mock)->send($request);

        $mock->assertSent(function (ConvergeNodeRequest $request, Response $response) use ($expected): bool {
            $pending = $response->getPendingRequest();
            expect($request->resolveEndpoint())->toBe('/api/v1/nodes/7/converge')
                ->and($pending->getMethod())->toBe(Method::POST)
                ->and($pending->getUrl())->toBe('https://gateway.example.test/api/v1/nodes/7/converge')
                ->and($pending->query()->all())->toBe([])
                ->and($pending->body()->all())->toBe($expected)
                ->and($pending->headers()->get('Idempotency-Key'))->toBeNull();

            return true;
        });
    })->with([
        'default scope' => [null, ['base' => false]],
        'selected roles' => [['app-dev', 'metrics'], ['base' => false, 'roles' => ['app-dev', 'metrics']]],
        'explicit empty selection' => [[], ['base' => false, 'roles' => []]],
        'Gateway owns role validation' => [['unknown'], ['base' => false, 'roles' => ['unknown']]],
    ]);
});

it('refuses a retired synchronous footprint response', function (): void {
    $request = new ConvergeNodeRequest(17, true);
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient(new MockClient([ConvergeNodeRequest::class => MockResponse::make([
        'data' => ['node_id' => 17, 'node' => 'services', 'artifacts' => ['agent' => 'applied'], 'digest' => str_repeat('a', 64), 'changed' => true],
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ], 200)]));
    expect(fn () => $connector->send($request)->dto())
        ->toThrow(InvalidArgumentException::class, 'Gateway response contains an invalid operation.');
});
