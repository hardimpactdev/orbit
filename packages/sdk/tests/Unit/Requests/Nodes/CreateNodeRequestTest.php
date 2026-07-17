<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Nodes;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Nodes\CreateNodeRequest;
use Orbit\Sdk\Laravel\Responses\Nodes\NodeCreateResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves canonical workload-role forwarding to POST /api/nodes', function (): void {
    $request = new CreateNodeRequest(
        name: 'app-1',
        roles: ['app-dev'],
        host: '192.0.2.20',
        tld: 'test',
        user: 'provisioner',
    );

    expect($request->resolveEndpoint())->toBe('/api/nodes');
    expect($request->getMethod())->toBe(Method::POST);
    expect($request->body()->all())->toBe([
        'name' => 'app-1',
        'roles' => ['app-dev'],
        'host' => '192.0.2.20',
        'tld' => 'test',
        'user' => 'provisioner',
    ]);
});

it('serializes explicit operator identity requests without a role value', function (): void {
    $request = new CreateNodeRequest(
        name: 'operator-1',
        roles: [],
        host: null,
        tld: 'operator',
        user: null,
        operator: true,
    );

    expect($request->body()->all())->toBe([
        'name' => 'operator-1',
        'roles' => [],
        'host' => null,
        'tld' => 'operator',
        'user' => null,
        'operator' => true,
    ]);
});

it('includes an expected host key fingerprint when supplied', function (): void {
    $request = new CreateNodeRequest(
        name: 'app-1',
        roles: ['app-prod'],
        host: '192.0.2.20',
        tld: 'production',
        user: 'ubuntu',
        hostKeyFingerprint: 'SHA256:expected',
    );

    expect($request->body()->all())->toBe([
        'name' => 'app-1',
        'roles' => ['app-prod'],
        'host' => '192.0.2.20',
        'tld' => 'production',
        'user' => 'ubuntu',
        'host_key_fingerprint' => 'SHA256:expected',
    ]);
});

it('serializes the selected Valkey provider for websocket nodes', function (): void {
    $request = new CreateNodeRequest(
        name: 'websocket-1',
        roles: ['websocket'],
        host: '192.0.2.30',
        tld: 'websocket',
        user: 'orbit',
        valkeyNode: 'database-1',
    );

    expect($request->body()->all())
        ->toMatchArray([
            'name' => 'websocket-1',
            'roles' => ['websocket'],
            'valkey_node' => 'database-1',
        ])
        ->not->toHaveKey('redis_node');
});

it('returns a NodeCreateResponse DTO with gateway data', function (): void {
    $mock = new MockClient([
        CreateNodeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'node' => [
                        'name' => 'app-1',
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new CreateNodeRequest('app-1', ['app-dev'], '192.0.2.20', 'test', 'orbit'))->dto();

    expect($dto)->toBeInstanceOf(NodeCreateResponse::class);
    expect($dto->data)->toBe([
        'node' => [
            'name' => 'app-1',
        ],
    ]);
});
