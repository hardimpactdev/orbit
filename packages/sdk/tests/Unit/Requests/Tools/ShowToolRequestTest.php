<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Tools;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Tools\ShowToolRequest;
use Orbit\Sdk\Laravel\Responses\Tools\ToolShowResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to GET /api/tools/{tool}', function (): void {
    $request = new ShowToolRequest(tool: 'composer');

    expect($request->resolveEndpoint())->toBe('/api/tools/composer');
    expect($request->getMethod())->toBe(Method::GET);
});

it('serializes app node and live filters when provided', function (): void {
    $request = new ShowToolRequest(tool: 'composer', instance: 'docs', node: 'app-1', live: true);

    expect($request->query()->all())->toBe([
        'instance' => 'docs',
        'node' => 'app-1',
        'live' => '1',
    ]);
});

it('omits null and false filters from the query', function (): void {
    $request = new ShowToolRequest(tool: 'composer', node: 'app-1');

    expect($request->query()->all())->toBe(['node' => 'app-1']);
});

it('returns a ToolShowResponse DTO with a tool', function (): void {
    $mock = new MockClient([
        ShowToolRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'tool' => [
                        'name' => 'composer',
                        'node' => 'app-1',
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new ShowToolRequest(tool: 'composer'))->dto();

    expect($dto)->toBeInstanceOf(ToolShowResponse::class);
    expect($dto->tool)->toBe([
        'name' => 'composer',
        'node' => 'app-1',
    ]);
});
