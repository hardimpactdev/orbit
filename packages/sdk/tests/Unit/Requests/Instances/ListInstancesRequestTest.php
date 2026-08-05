<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Instances;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Laravel\Responses\Instances\InstanceListResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);

it('resolves to GET /api/instances', function (): void {
    $request = new ListInstancesRequest;

    expect($request->resolveEndpoint())
        ->toBe('/api/instances')
        ->and($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->query()->all())
        ->toBe([]);
});

it('serializes the app filter', function (): void {
    expect(new ListInstancesRequest(app: 'mealou')->query()->all())
        ->toBe(['app' => 'mealou']);
});

it('returns an instance list response', function (): void {
    $mock = new MockClient([
        ListInstancesRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'instances' => [[
                        'app' => 'mealou',
                        'name' => 'production',
                    ]],
                ],
            ],
        ], 200),
    ]);
    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new ListInstancesRequest)->dto();

    expect($dto)
        ->toBeInstanceOf(InstanceListResponse::class)
        ->and($dto->instances)
        ->toBe([[
            'app' => 'mealou',
            'name' => 'production',
        ]]);
});
