<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\CreateNodeRequest;
use App\Http\Gateway\Responses\Nodes\NodeCreateResponse;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $settings = LocalGatewaySettings::current();
    $settings->gateway_url = 'https://10.6.0.2';
    $settings->ca_pem_path = '/path/to/ca.pem';
    $settings->save();
});

it('resolves canonical hosted-role forwarding to POST /api/nodes without legacy environment', function (): void {
    $request = new CreateNodeRequest(
        name: 'app-1',
        role: 'app-development',
        roles: ['app-development'],
        host: '192.0.2.20',
        environment: null,
        tld: 'test',
        user: 'provisioner',
    );

    expect($request->resolveEndpoint())->toBe('/api/nodes');
    expect($request->getMethod())->toBe(Method::POST);
    expect($request->body()->all())->toBe([
        'name' => 'app-1',
        'role' => 'app-development',
        'roles' => ['app-development'],
        'host' => '192.0.2.20',
        'environment' => null,
        'tld' => 'test',
        'user' => 'provisioner',
    ]);
});

it('resolves legacy app forwarding to POST /api/nodes with legacy environment', function (): void {
    $request = new CreateNodeRequest(
        name: 'app-1',
        role: 'app',
        roles: ['app'],
        host: '192.0.2.20',
        environment: 'development',
        tld: 'test',
        user: 'provisioner',
    );

    expect($request->body()->all())->toBe([
        'name' => 'app-1',
        'role' => 'app',
        'roles' => ['app'],
        'host' => '192.0.2.20',
        'environment' => 'development',
        'tld' => 'test',
        'user' => 'provisioner',
    ]);
});

it('returns a NodeCreateResponse DTO with gateway data', function (): void {
    $mock = new MockClient([
        CreateNodeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'node' => [
                        'name' => 'app-1',
                        'role' => 'app',
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new CreateNodeRequest('app-1', 'app-development', ['app-development'], '192.0.2.20', null, 'test', 'orbit'))->dto();

    expect($dto)->toBeInstanceOf(NodeCreateResponse::class);
    expect($dto->data)->toBe([
        'node' => [
            'name' => 'app-1',
            'role' => 'app',
        ],
    ]);
});
