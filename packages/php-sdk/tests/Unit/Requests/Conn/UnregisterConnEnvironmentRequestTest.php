<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Conn\UnregisterConnEnvironmentRequest;
use Orbit\Sdk\Responses\Conn\UnregisteredConnEnvironmentResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(fn () => MockClient::destroyGlobal());
afterEach(fn () => MockClient::destroyGlobal());

it('sends DELETE to the conn environment unregister endpoint', function (): void {
    $request = new UnregisterConnEnvironmentRequest('2f6cdbde-0000-4000-8000-000000000001');

    expect($request->getMethod())->toBe(Method::DELETE)
        ->and($request->resolveEndpoint())->toBe('/api/v1/conn/environments/2f6cdbde-0000-4000-8000-000000000001');
});

it('parses removed true and false responses', function (bool $removed): void {
    $requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
    $connector = new GatewayConnector('https://gateway.orbit', caPemPath: '/tmp/root.pem');
    MockClient::global([
        UnregisterConnEnvironmentRequest::class => MockResponse::make([
            'data' => [
                'environment_id' => '2f6cdbde-0000-4000-8000-000000000001',
                'removed' => $removed,
            ],
            'meta' => ['request_id' => $requestId],
        ]),
    ]);

    $response = $connector->send(new UnregisterConnEnvironmentRequest('2f6cdbde-0000-4000-8000-000000000001'))->dto();

    expect($response)->toBeInstanceOf(UnregisteredConnEnvironmentResponse::class)
        ->and($response->environmentId)->toBe('2f6cdbde-0000-4000-8000-000000000001')
        ->and($response->removed)->toBe($removed)
        ->and($response->requestId)->toBe($requestId);
})->with(['removed' => [true], 'already gone' => [false]]);

it('rejects an invalid unregister response payload', function (): void {
    expect(fn () => UnregisteredConnEnvironmentResponse::fromGatewayData(['environment_id' => 'env-1'], 'req-1'))
        ->toThrow(InvalidArgumentException::class, 'invalid Conn environment unregister data');
});
