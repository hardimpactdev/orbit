<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Processes;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Processes\StartProcessesRequest;
use Orbit\Sdk\Laravel\Responses\Processes\ProcessStartResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);

it('targets the process start gateway endpoint with optional filters', function (): void {
    $request = new StartProcessesRequest(instance: 'docs', workspace: 'feature-docs', name: 'vite');

    expect($request->getMethod())
        ->toBe(Method::POST)
        ->and($request->resolveEndpoint())
        ->toBe('/api/processes/start')
        ->and($request->body()->all())
        ->toBe([
            'instance' => 'docs',
            'workspace' => 'feature-docs',
            'name' => 'vite',
        ]);
});

it('omits null request body values', function (): void {
    $request = new StartProcessesRequest(instance: 'docs', workspace: null, name: null);

    expect($request->body()->all())->toBe(['instance' => 'docs']);
});

it('returns a ProcessStartResponse DTO', function (): void {
    $mock = new MockClient([
        StartProcessesRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'runtimes' => [
                        [
                            'process' => 'vite',
                            'instance' => 'docs',
                            'workspace' => null,
                            'runtime_unit' => 'orbit_docs_main_vite',
                            'state' => 'running',
                            'event' => ['type' => 'started'],
                        ],
                    ],
                ],
                'meta' => [],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new StartProcessesRequest(instance: 'docs', workspace: null, name: 'vite'))->dto();

    expect($dto)
        ->toBeInstanceOf(ProcessStartResponse::class)
        ->and($dto->data['runtimes'][0]['runtime_unit'])
        ->toBe('orbit_docs_main_vite');
});
