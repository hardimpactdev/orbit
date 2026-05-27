<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\DefaultNodeClearRequest;
use App\Http\Gateway\Requests\Nodes\DefaultNodeSetRequest;
use App\Http\Gateway\Requests\Nodes\DefaultNodeShowRequest;
use App\Http\Gateway\Responses\Nodes\NodeDefaultResponse;
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

it('show resolves to GET /api/nodes/default', function (): void {
    $request = new DefaultNodeShowRequest;

    expect($request->resolveEndpoint())->toBe('/api/nodes/default');
    expect($request->getMethod())->toBe(Method::GET);
});

it('set resolves to PUT /api/nodes/default with name body', function (): void {
    $request = new DefaultNodeSetRequest('app-1');

    expect($request->resolveEndpoint())->toBe('/api/nodes/default');
    expect($request->getMethod())->toBe(Method::PUT);
    expect($request->body()->all())->toBe(['name' => 'app-1']);
});

it('clear resolves to DELETE /api/nodes/default', function (): void {
    $request = new DefaultNodeClearRequest;

    expect($request->resolveEndpoint())->toBe('/api/nodes/default');
    expect($request->getMethod())->toBe(Method::DELETE);
});

it('returns a NodeDefaultResponse DTO from show', function (): void {
    $mock = new MockClient([
        DefaultNodeShowRequest::class => MockResponse::make([
            'success' => ['data' => ['default_node' => 'app-1']],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new DefaultNodeShowRequest)->dto();

    expect($dto)->toBeInstanceOf(NodeDefaultResponse::class);
    expect($dto->defaultNode)->toBe('app-1');
});

it('returns a NodeDefaultResponse with null when no default is set', function (): void {
    $mock = new MockClient([
        DefaultNodeShowRequest::class => MockResponse::make([
            'success' => ['data' => ['default_node' => null]],
        ], 200),
    ]);

    $connector = new GatewayConnector;
    $connector->withMockClient($mock);

    $dto = $connector->send(new DefaultNodeShowRequest)->dto();

    expect($dto->defaultNode)->toBeNull();
});
