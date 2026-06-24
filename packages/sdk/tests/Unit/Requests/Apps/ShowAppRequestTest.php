<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Apps;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Apps\ShowAppRequest;
use Orbit\Sdk\Laravel\Responses\Apps\AppShowResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to GET /api/apps/{app}', function (): void {
    $request = new ShowAppRequest('docs app');

    expect($request->resolveEndpoint())->toBe('/api/apps/docs%20app');
    expect($request->getMethod())->toBe(Method::GET);
});

it('returns an AppShowResponse DTO with app and details', function (): void {
    $mock = new MockClient([
        ShowAppRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'app' => ['name' => 'docs'],
                    'details' => ['workspaces' => []],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new ShowAppRequest('docs'))->dto();

    expect($dto)
        ->toBeInstanceOf(AppShowResponse::class)
        ->and($dto->app)
        ->toBe(['name' => 'docs'])
        ->and($dto->details)
        ->toBe(['workspaces' => []]);
});
