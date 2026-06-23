<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Nodes;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Laravel\Responses\Nodes\NodeListResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to GET /api/nodes', function (): void {
    $request = new ListNodesRequest;

    expect($request->resolveEndpoint())->toBe('/api/nodes');
    expect($request->getMethod())->toBe(Method::GET);
});

it('serializes role and doctor as query parameters when provided', function (): void {
    $request = new ListNodesRequest(role: 'app-prod', doctor: true);

    expect($request->query()->all())->toBe([
        'role' => 'app-prod',
        'doctor' => true,
    ]);
});

it('omits null filters from the query', function (): void {
    $request = new ListNodesRequest(role: 'gateway');

    expect($request->query()->all())->toBe(['role' => 'gateway']);
});

it('returns a NodeListResponse DTO with nodes and meta', function (): void {
    $mock = new MockClient([
        ListNodesRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'nodes' => [
                        ['name' => 'gw-1', 'roles' => [['role' => 'gateway', 'status' => 'active']]],
                        ['name' => 'app-1', 'roles' => [['role' => 'app-dev', 'status' => 'active']]],
                    ],
                ],
                'meta' => ['doctor' => ['issues' => 0]],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new ListNodesRequest)->dto();

    expect($dto)->toBeInstanceOf(NodeListResponse::class);
    expect($dto->nodes)->toBe([
        ['name' => 'gw-1', 'roles' => [['role' => 'gateway', 'status' => 'active']]],
        ['name' => 'app-1', 'roles' => [['role' => 'app-dev', 'status' => 'active']]],
    ]);
    expect($dto->meta)->toBe(['doctor' => ['issues' => 0]]);
});
