<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Processes;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Processes\ShowProcessLogsRequest;
use Orbit\Sdk\Laravel\Responses\Processes\ProcessLogsResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);

it('targets the process log endpoint with query filters', function (): void {
    $request = new ShowProcessLogsRequest(name: 'vite', app: 'docs', workspace: 'feature-docs', lines: 50);

    expect($request->getMethod())->toBe(Method::GET)
        ->and($request->resolveEndpoint())->toBe('/api/processes/vite/log')
        ->and($request->query()->all())->toBe([
            'app' => 'docs',
            'workspace' => 'feature-docs',
            'lines' => 50,
        ]);
});

it('returns a ProcessLogsResponse DTO with meta', function (): void {
    $mock = new MockClient([
        ShowProcessLogsRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'logs' => [
                        'process' => 'vite',
                        'app' => 'docs',
                        'workspace' => null,
                        'runtime_unit' => 'orbit_docs_main_vite',
                        'lines' => [['timestamp' => null, 'message' => 'Vite ready']],
                    ],
                ],
                'meta' => ['line_count' => 1],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new ShowProcessLogsRequest(name: 'vite', app: 'docs', workspace: null))->dto();

    expect($dto)->toBeInstanceOf(ProcessLogsResponse::class)
        ->and($dto->data['logs']['runtime_unit'])->toBe('orbit_docs_main_vite')
        ->and($dto->meta['line_count'])->toBe(1);
});
