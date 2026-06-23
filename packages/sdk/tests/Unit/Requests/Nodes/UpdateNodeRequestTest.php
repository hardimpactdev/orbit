<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Nodes;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Nodes\UpdateNodeRequest;
use Orbit\Sdk\Laravel\Responses\Nodes\NodeUpdateResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to PUT /api/nodes/{name} with non-null fields only', function (): void {
    $request = new UpdateNodeRequest('app-1', ['public_ipv4' => '203.0.113.10', 'platform' => null]);

    expect($request->resolveEndpoint())->toBe('/api/nodes/app-1');
    expect($request->getMethod())->toBe(Method::PUT);
    expect($request->body()->all())->toBe(['public_ipv4' => '203.0.113.10']);
});

it('returns a NodeUpdateResponse DTO with updated name and changed fields', function (): void {
    $mock = new MockClient([
        UpdateNodeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'name' => 'app-1',
                    'changed' => ['public_ipv4'],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new UpdateNodeRequest('app-1', ['public_ipv4' => '203.0.113.10']))->dto();

    expect($dto)->toBeInstanceOf(NodeUpdateResponse::class);
    expect($dto->name)->toBe('app-1');
    expect($dto->changed)->toBe(['public_ipv4']);
});
