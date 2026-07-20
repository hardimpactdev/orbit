<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Tools;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Tools\LogsToolRequest;
use Orbit\Sdk\Laravel\Requests\Tools\ReloadToolRequest;
use Orbit\Sdk\Laravel\Requests\Tools\RestartToolRequest;
use Orbit\Sdk\Laravel\Requests\Tools\StartToolRequest;
use Orbit\Sdk\Laravel\Requests\Tools\StopToolRequest;
use Orbit\Sdk\Laravel\Responses\Tools\ToolLogsResponse;
use Orbit\Sdk\Laravel\Responses\Tools\ToolShowResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);

it('builds typed tool lifecycle requests', function (string $requestClass, string $action): void {
    $request = new $requestClass(tool: 'openclaw', instance: 'docs', node: 'app-1');

    expect($request->resolveEndpoint())
        ->toBe("/api/tools/openclaw/{$action}")
        ->and($request->getMethod())
        ->toBe(Method::POST)
        ->and($request->body()->all())
        ->toBe([
            'instance' => 'docs',
            'node' => 'app-1',
        ]);

    $mock = new MockClient([
        $requestClass => MockResponse::make([
            'success' => [
                'data' => [
                    'tool' => [
                        'name' => 'openclaw',
                        'action' => $action,
                    ],
                ],
            ],
        ], 200),
    ]);
    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send($request)->dto();

    expect($dto)
        ->toBeInstanceOf(ToolShowResponse::class)
        ->and($dto->tool)
        ->toMatchArray([
            'name' => 'openclaw',
            'action' => $action,
        ]);
})->with([
    [StartToolRequest::class,   'start'],
    [StopToolRequest::class,    'stop'],
    [RestartToolRequest::class, 'restart'],
    [ReloadToolRequest::class,  'reload'],
]);

it('builds the typed tool logs request and response', function (): void {
    $request = new LogsToolRequest(tool: 'dns', instance: 'docs', node: 'gateway-1', lines: 25);

    expect($request->resolveEndpoint())
        ->toBe('/api/tools/dns/logs')
        ->and($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->query()->all())
        ->toBe([
            'instance' => 'docs',
            'node' => 'gateway-1',
            'lines' => 25,
        ]);

    $mock = new MockClient([
        LogsToolRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'logs' => [
                        'tool' => 'dns',
                        'lines' => [
                            ['message' => 'dns ready'],
                        ],
                    ],
                ],
                'meta' => [
                    'line_count' => 1,
                ],
            ],
        ], 200),
    ]);
    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send($request)->dto();

    expect($dto)
        ->toBeInstanceOf(ToolLogsResponse::class)
        ->and($dto->logs['tool'])
        ->toBe('dns')
        ->and($dto->lineCount)
        ->toBe(1);
});
