<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Processes;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Processes\UpdateProcessRequest;
use Orbit\Sdk\Laravel\Responses\Processes\ProcessUpdateResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);

it('resolves to PATCH /api/processes/{name}', function (): void {
    $request = new UpdateProcessRequest(instance: 'docs', name: 'vite', command: 'npm run dev');

    expect($request->resolveEndpoint())->toBe('/api/processes/vite');
    expect($request->getMethod())->toBe(Method::PATCH);
});

it('serializes only supplied editable fields', function (): void {
    $request = new UpdateProcessRequest(
        instance: 'docs',
        name: 'vite',
        command: 'npm run dev -- --host=0.0.0.0',
        crashNotification: 'agent_ide',
        restart: true,
    );

    expect($request->body()->all())->toBe([
        'instance' => 'docs',
        'command' => 'npm run dev -- --host=0.0.0.0',
        'crash_notification' => 'agent_ide',
        'restart' => true,
    ]);
});

it('omits the runtime field when none was supplied', function (): void {
    $request = new UpdateProcessRequest(instance: 'docs', name: 'vite', command: 'npm run dev');

    expect($request->body()->all())->not->toHaveKey('runtime');
});

it('serializes a runtime change into the request body', function (): void {
    $request = new UpdateProcessRequest(
        instance: 'docs',
        name: 'queue',
        runtime: 'systemd',
    );

    expect($request->body()->all())->toMatchArray([
        'instance' => 'docs',
        'runtime' => 'systemd',
    ]);
});

it('returns a ProcessUpdateResponse DTO with warnings', function (): void {
    $mock = new MockClient([
        UpdateProcessRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'process' => ['name' => 'vite', 'instance' => 'docs'],
                    'changed' => ['command'],
                    'runtime_units' => [['name' => 'orbit_docs_main_vite', 'context' => 'main']],
                ],
                'meta' => [
                    'warnings' => [
                        ['code' => 'process.runtime_unit_restart_failed'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new UpdateProcessRequest(instance: 'docs', name: 'vite', command: 'npm run dev'))->dto();

    expect($dto)->toBeInstanceOf(ProcessUpdateResponse::class);
    expect($dto->data['changed'])->toBe(['command']);
    expect($dto->warnings)->toBe([
        ['code' => 'process.runtime_unit_restart_failed'],
    ]);
});
