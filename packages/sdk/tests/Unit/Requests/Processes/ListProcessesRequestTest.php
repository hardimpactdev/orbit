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

it('serializes node, app, and workspace filters when provided', function (): void {
    $request = new ListProcessesRequest(node: 'app-1', instance: 'docs', workspace: 'feature-docs');

    expect($request->query()->all())->toBe([
        'node' => 'app-1',
        'instance' => 'docs',
        'workspace' => 'feature-docs',
    ]);
});

it('serializes the app hostname selector', function (): void {
    $request = new ListProcessesRequest(app: 'test.app.example');

    expect($request->query()->all())->toBe([
        'app' => 'test.app.example',
    ]);
});

it('omits null filters from the query', function (): void {
    $request = new ListProcessesRequest(instance: 'docs');

    expect($request->query()->all())->toBe(['instance' => 'docs']);
});

it('returns a ProcessListResponse DTO with context and processes', function (): void {
    $mock = new MockClient([
        ListProcessesRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'context' => ['node' => 'app-1', 'instance' => 'docs', 'workspace' => null],
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
    expect($dto->context)->toBe(['node' => 'app-1', 'instance' => 'docs', 'workspace' => null]);
    expect($dto->processes)->toBe([
        ['name' => 'queue', 'command' => 'php artisan queue:work'],
    ]);
});
