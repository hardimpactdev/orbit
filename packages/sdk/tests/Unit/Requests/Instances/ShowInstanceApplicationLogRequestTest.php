<?php

declare(strict_types=1);

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Instances\ShowInstanceApplicationLogRequest;
use Orbit\Sdk\Laravel\Responses\ApplicationLogs\ApplicationLogResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('targets the instance application log endpoint with lines and node', function (): void {
    $request = new ShowInstanceApplicationLogRequest(
        instance: 'docs.development',
        lines: 50,
        node: 'app-dev-1',
    );

    expect($request->resolveEndpoint())
        ->toBe('/api/instances/docs.development/log')
        ->and($request->query()->all())
        ->toMatchArray([
            'lines' => 50,
            'node' => 'app-dev-1',
        ]);
});

it('returns an ApplicationLogResponse DTO', function (): void {
    $mockClient = new MockClient([
        ShowInstanceApplicationLogRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'path' => 'storage/logs/laravel.log',
                    'file_exists' => false,
                    'lines' => [],
                ],
                'meta' => [],
            ],
        ]),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://gateway.test');
    $connector->withMockClient($mockClient);

    $dto = $connector->send(new ShowInstanceApplicationLogRequest(instance: 'docs.development'))->dto();

    expect($dto)
        ->toBeInstanceOf(ApplicationLogResponse::class)
        ->and($dto->data['path'])
        ->toBe('storage/logs/laravel.log');
});
