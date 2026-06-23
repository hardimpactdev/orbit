<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Nodes;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Nodes\ShowNodeRequest;
use Orbit\Sdk\Laravel\Responses\Nodes\NodeShowResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to GET /api/nodes/{name}', function (): void {
    $request = new ShowNodeRequest('gw-1');

    expect($request->resolveEndpoint())->toBe('/api/nodes/gw-1');
    expect($request->getMethod())->toBe(Method::GET);
});

it('returns a NodeShowResponse DTO with node array', function (): void {
    $mock = new MockClient([
        ShowNodeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'node' => [
                        'name' => 'gw-1',
                        'role' => 'gateway',
                        'status' => 'active',
                        'wireguard_address' => '10.6.0.2',
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new ShowNodeRequest('gw-1'))->dto();

    expect($dto)->toBeInstanceOf(NodeShowResponse::class);
    expect($dto->node)->toMatchArray([
        'name' => 'gw-1',
        'role' => 'gateway',
        'status' => 'active',
        'wireguard_address' => '10.6.0.2',
    ]);
});
