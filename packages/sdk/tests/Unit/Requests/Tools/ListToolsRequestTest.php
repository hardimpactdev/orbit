<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Tools;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Tools\ListToolsRequest;
use Orbit\Sdk\Laravel\Responses\Tools\ToolListResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to GET /api/tools', function (): void {
    $request = new ListToolsRequest;

    expect($request->resolveEndpoint())->toBe('/api/tools');
    expect($request->getMethod())->toBe(Method::GET);
});

it('serializes app, node, and self filters when provided', function (): void {
    $request = new ListToolsRequest(app: 'docs', node: 'app-1', self: true);

    expect($request->query()->all())->toBe([
        'app' => 'docs',
        'node' => 'app-1',
        'self' => true,
    ]);
});

it('omits null filters from the query', function (): void {
    $request = new ListToolsRequest(node: 'app-1');

    expect($request->query()->all())->toBe(['node' => 'app-1']);
});

it('returns a ToolListResponse DTO with tools', function (): void {
    $mock = new MockClient([
        ListToolsRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'tools' => [
                        ['name' => 'composer', 'node' => 'app-1'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new ListToolsRequest)->dto();

    expect($dto)->toBeInstanceOf(ToolListResponse::class);
    expect($dto->tools)->toBe([
        ['name' => 'composer', 'node' => 'app-1'],
    ]);
});
