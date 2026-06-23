<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Processes;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Processes\ListProcessesRequest;
use Orbit\Sdk\Laravel\Responses\Processes\ProcessListResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to GET /api/processes', function (): void {
    $request = new ListProcessesRequest;

    expect($request->resolveEndpoint())->toBe('/api/processes');
    expect($request->getMethod())->toBe(Method::GET);
});

it('serializes app and workspace filters when provided', function (): void {
    $request = new ListProcessesRequest(app: 'docs', workspace: 'feature-docs');

    expect($request->query()->all())->toBe([
        'app' => 'docs',
        'workspace' => 'feature-docs',
    ]);
});

it('omits null filters from the query', function (): void {
    $request = new ListProcessesRequest(app: 'docs');

    expect($request->query()->all())->toBe(['app' => 'docs']);
});

it('returns a ProcessListResponse DTO with context and processes', function (): void {
    $mock = new MockClient([
        ListProcessesRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'context' => ['app' => 'docs', 'workspace' => null],
                    'processes' => [
                        ['name' => 'queue', 'command' => 'php artisan queue:work'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new ListProcessesRequest)->dto();

    expect($dto)->toBeInstanceOf(ProcessListResponse::class);
    expect($dto->context)->toBe(['app' => 'docs', 'workspace' => null]);
    expect($dto->processes)->toBe([
        ['name' => 'queue', 'command' => 'php artisan queue:work'],
    ]);
});
