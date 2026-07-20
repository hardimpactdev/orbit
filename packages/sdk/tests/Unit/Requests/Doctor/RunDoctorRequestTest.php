<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Doctor;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Doctor\RunDoctorRequest;
use Orbit\Sdk\Laravel\Responses\Doctor\DoctorRunResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to POST /api/doctor/run', function (): void {
    $request = new RunDoctorRequest(families: ['node']);

    expect($request->resolveEndpoint())->toBe('/api/doctor/run');
    expect($request->getMethod())->toBe(Method::POST);
});

it('serializes doctor filters into the body', function (): void {
    $request = new RunDoctorRequest(
        families: ['node'],
        node: 'app-1',
        self: true,
        instance: 'docs',
        workspace: 'main',
        key: 'node.security.host_key.app-1',
    );

    expect($request->body()->all())->toBe([
        'families' => ['node'],
        'node' => 'app-1',
        'self' => true,
        'instance' => 'docs',
        'workspace' => 'main',
        'key' => 'node.security.host_key.app-1',
    ]);
});

it('returns a DoctorRunResponse DTO', function (): void {
    $mock = new MockClient([
        RunDoctorRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'doctor' => [
                        'healthy' => true,
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new RunDoctorRequest(families: ['node']))->dto();

    expect($dto)->toBeInstanceOf(DoctorRunResponse::class);
    expect($dto->doctor)->toBe(['healthy' => true]);
});
