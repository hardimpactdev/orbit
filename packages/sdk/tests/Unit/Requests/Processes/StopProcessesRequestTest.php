<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Processes;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Processes\StopProcessesRequest;
use Orbit\Sdk\Laravel\Responses\Processes\ProcessStopResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);

it('targets the process stop gateway endpoint with optional filters', function (): void {
    $request = new StopProcessesRequest(instance: 'docs', workspace: 'feature-docs', name: 'vite');

    expect($request->getMethod())
        ->toBe(Method::POST)
        ->and($request->resolveEndpoint())
        ->toBe('/api/processes/stop')
        ->and($request->body()->all())
        ->toBe([
            'instance' => 'docs',
            'workspace' => 'feature-docs',
            'name' => 'vite',
        ]);
});

it('returns a ProcessStopResponse DTO', function (): void {
    $mock = new MockClient([
        StopProcessesRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'runtimes' => [
                        [
                            'process' => 'vite',
                            'instance' => 'docs',
                            'workspace' => null,
                            'runtime_unit' => 'orbit_docs_main_vite',
                            'state' => 'stopped',
                            'event' => ['type' => 'stopped'],
                        ],
                    ],
                ],
                'meta' => [],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new StopProcessesRequest(instance: 'docs', workspace: null, name: 'vite'))->dto();

    expect($dto)->toBeInstanceOf(ProcessStopResponse::class)->and($dto->data['runtimes'][0]['state'])->toBe('stopped');
});
