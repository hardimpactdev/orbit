<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Processes;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Processes\RestartProcessesRequest;
use Orbit\Sdk\Laravel\Responses\Processes\ProcessRestartResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);

it('targets the process restart gateway endpoint with optional filters', function (): void {
    $request = new RestartProcessesRequest(app: 'docs', workspace: 'feature-docs', name: 'vite');

    expect($request->getMethod())->toBe(Method::POST)
        ->and($request->resolveEndpoint())->toBe('/api/processes/restart')
        ->and($request->body()->all())->toBe([
            'app' => 'docs',
            'workspace' => 'feature-docs',
            'name' => 'vite',
        ]);
});

it('returns a ProcessRestartResponse DTO', function (): void {
    $mock = new MockClient([
        RestartProcessesRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'runtimes' => [
                        [
                            'process' => 'vite',
                            'app' => 'docs',
                            'workspace' => null,
                            'runtime_unit' => 'orbit_docs_main_vite',
                            'state' => 'running',
                            'events' => [['type' => 'stopped'], ['type' => 'started']],
                        ],
                    ],
                ],
                'meta' => [],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new RestartProcessesRequest(app: 'docs', workspace: null, name: 'vite'))->dto();

    expect($dto)->toBeInstanceOf(ProcessRestartResponse::class)
        ->and($dto->data['runtimes'][0]['state'])->toBe('running');
});
