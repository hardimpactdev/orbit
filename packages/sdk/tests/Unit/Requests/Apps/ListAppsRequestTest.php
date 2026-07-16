<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Apps;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Apps\ListAppsRequest;
use Orbit\Sdk\Laravel\Responses\Apps\AppListResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to GET /api/apps', function (): void {
    $request = new ListAppsRequest;

    expect($request->resolveEndpoint())->toBe('/api/apps');
    expect($request->getMethod())->toBe(Method::GET);
    expect(
        array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            new \ReflectionClass(ListAppsRequest::class)->getConstructor()?->getParameters() ?? [],
        ),
    )->toBe(['environment']);
});

it('serializes the environment filter when provided', function (): void {
    $request = new ListAppsRequest(environment: 'production');

    expect($request->query()->all())->toBe([
        'environment' => 'production',
    ]);
});

it('omits null filters from the query', function (): void {
    $request = new ListAppsRequest(environment: 'development');

    expect($request->query()->all())->toBe(['environment' => 'development']);
});

it('returns an AppListResponse DTO with apps', function (): void {
    $mock = new MockClient([
        ListAppsRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'apps' => [
                        ['name' => 'docs', 'node' => 'app-1'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new ListAppsRequest)->dto();

    expect($dto)->toBeInstanceOf(AppListResponse::class);
    expect($dto->apps)->toBe([
        ['name' => 'docs', 'node' => 'app-1'],
    ]);
});
