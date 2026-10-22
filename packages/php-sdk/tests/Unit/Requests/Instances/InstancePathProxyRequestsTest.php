<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Instances\SetInstancePathProxyRequest;
use Orbit\Sdk\Requests\Instances\ShowInstancePathProxyRequest;
use Orbit\Sdk\Requests\Instances\UnsetInstancePathProxyRequest;
use Orbit\Sdk\Responses\Instances\InstancePathProxyResponse;
use Orbit\Sdk\Responses\Instances\RemovedInstancePathProxyResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('uses typed methods and escaped Instance selectors', function (GatewayRequest $request, Method $method, string $endpoint): void {
    expect($request->getMethod())->toBe($method)->and($request->resolveEndpoint())->toBe($endpoint);
})->with([
    [new SetInstancePathProxyRequest(71, 123, 8080), Method::PUT, '/api/v1/instances/71/path-proxy'],
    [new ShowInstancePathProxyRequest('hauzer.app'), Method::GET, '/api/v1/instances/hauzer.app/path-proxy'],
    [new UnsetInstancePathProxyRequest('route/name'), Method::DELETE, '/api/v1/instances/route%2Fname/path-proxy'],
]);

it('omits only an absent prefix and keeps reads and removal bodyless', function (): void {
    expect(new SetInstancePathProxyRequest(71, 123, 8080)->body()->all())->toBe(['process_id' => 123, 'port' => 8080])
        ->and(new SetInstancePathProxyRequest(71, 123, 8080, '')->body()->all())->toBe(['process_id' => 123, 'port' => 8080, 'prefix' => ''])
        ->and(new ShowInstancePathProxyRequest(71))->not->toBeInstanceOf(HasBody::class)
        ->and(new UnsetInstancePathProxyRequest(71))->not->toBeInstanceOf(HasBody::class);
});

it('decodes the publication without retaining extra service data', function (GatewayRequest $request): void {
    $requestId = '11111111-1111-4111-8111-111111111111';
    $data = ['instance_id' => 71, 'route_id' => 100, 'process_id' => 123, 'port' => 8080,
        'prefix' => '/powersync', 'endpoint' => 'https://hauzer.app/powersync', 'enabled' => false];
    $connector = new GatewayConnector('https://gateway.orbit', '/tmp/test-ca.pem');
    $connector->withMockClient(new MockClient([$request::class => MockResponse::make([
        'data' => [...$data, 'runtime_config' => ['environment' => ['TOKEN' => 'sdk-path-proxy-secret-sentinel']]], 'meta' => ['request_id' => $requestId],
    ])]));
    $result = $connector->send($request)->dto();
    expect($result)->toBeInstanceOf(InstancePathProxyResponse::class)
        ->and($result->toArray())->toBe([...$data, 'request_id' => $requestId])
        ->and(print_r($result, true))->not->toContain('sdk-path-proxy-secret-sentinel');
})->with([new SetInstancePathProxyRequest(71, 123, 8080), new ShowInstancePathProxyRequest(71)]);

it('decodes removal and preserves structured Gateway failures', function (): void {
    $requestId = '11111111-1111-4111-8111-111111111111';
    $connector = new GatewayConnector('https://gateway.orbit', '/tmp/test-ca.pem');
    $connector->withMockClient(new MockClient([
        UnsetInstancePathProxyRequest::class => MockResponse::make(['data' => ['instance_id' => 71], 'meta' => ['request_id' => $requestId]]),
        ShowInstancePathProxyRequest::class => MockResponse::make(['error' => ['code' => 'path_proxy.missing', 'message' => 'No publication.']], 404, ['X-Orbit-Request-Id' => $requestId]),
    ]));
    expect($connector->send(new UnsetInstancePathProxyRequest(71))->dto())->toBeInstanceOf(RemovedInstancePathProxyResponse::class);
    expect(fn () => $connector->send(new ShowInstancePathProxyRequest(71)))->toThrow(function (GatewayApiException $exception) use ($requestId): void {
        expect($exception->errorCode())->toBe('path_proxy.missing')->and($exception->requestId())->toBe($requestId);
    });
});

it('rejects malformed publication fields without echoing remote values', function (array $invalid): void {
    $data = ['instance_id' => 71, 'route_id' => 100, 'process_id' => 123, 'port' => 8080,
        'prefix' => '/powersync', 'endpoint' => 'https://hauzer.app/powersync', 'enabled' => true, ...$invalid];
    expect(fn () => InstancePathProxyResponse::fromGatewayData($data, ''))->toThrow(InvalidArgumentException::class);
})->with([
    [['endpoint' => 'https://user:secret@hauzer.app/powersync']],
    [['endpoint' => 'https://hauzer.app?secret=/powersync']],
    [['endpoint' => "https://hauzer.app/\npowersync"]],
    [['endpoint' => 'https://hauzer.app/other']],
    [['prefix' => '']],
    [['port' => 65536]], [['process_id' => '123']], [['route_id' => 0]], [['enabled' => 'false']], [['endpoint' => 'http://hauzer.app/powersync']],
]);
