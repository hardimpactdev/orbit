<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Doctor;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Doctor\FixDoctorRequest;
use Orbit\Sdk\Laravel\Responses\Doctor\DoctorRunResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to POST /api/doctor/fix', function (): void {
    $request = new FixDoctorRequest(mode: 'restore', families: ['node']);

    expect($request->resolveEndpoint())->toBe('/api/doctor/fix');
    expect($request->getMethod())->toBe(Method::POST);
});

it('serializes doctor fix filters into the body', function (): void {
    $issues = [['family' => 'proxy', 'key' => 'proxy.route_missing']];
    $request = new FixDoctorRequest(
        mode: 'restore',
        families: ['proxy'],
        issues: $issues,
        node: 'app-1',
        self: true,
        app: 'docs',
        workspace: 'main',
        key: 'proxy.route_missing',
        dryRun: true,
    );

    expect($request->body()->all())->toBe([
        'mode' => 'restore',
        'families' => ['proxy'],
        'issues' => $issues,
        'node' => 'app-1',
        'self' => true,
        'app' => 'docs',
        'workspace' => 'main',
        'key' => 'proxy.route_missing',
        'dry_run' => true,
    ]);
});

it('returns a DoctorRunResponse DTO', function (): void {
    $mock = new MockClient([
        FixDoctorRequest::class => MockResponse::make([
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

    $dto = $connector->send(new FixDoctorRequest(mode: 'restore', families: ['node']))->dto();

    expect($dto)->toBeInstanceOf(DoctorRunResponse::class);
    expect($dto->doctor)->toBe(['healthy' => true]);
});
