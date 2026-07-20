<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Dashboard;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Dashboard\ShowRuntimeInventoryRequest;
use Orbit\Sdk\Laravel\Responses\Dashboard\RuntimeInventoryResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);

it('resolves to GET /api/dashboard/runtime-inventory', function (): void {
    $request = new ShowRuntimeInventoryRequest;

    expect($request->resolveEndpoint())->toBe('/api/dashboard/runtime-inventory');
    expect($request->getMethod())->toBe(Method::GET);
});

it('returns a runtime inventory DTO', function (): void {
    $mock = new MockClient([
        ShowRuntimeInventoryRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'nodes' => [['name' => 'app-1']],
                    'projects' => [['name' => 'docs', 'node' => 'app-1']],
                    'processes' => [['name' => 'queue', 'node' => 'app-1']],
                    'tools' => [['name' => 'composer', 'node' => 'app-1']],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new ShowRuntimeInventoryRequest)->dto();

    expect($dto)->toBeInstanceOf(RuntimeInventoryResponse::class);
    expect($dto->nodes)->toBe([['name' => 'app-1']]);
    expect($dto->projects)->toBe([['name' => 'docs', 'node' => 'app-1']]);
    expect($dto->processes)->toBe([['name' => 'queue', 'node' => 'app-1']]);
    expect($dto->tools)->toBe([['name' => 'composer', 'node' => 'app-1']]);
});
