<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Profile;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Profile\ShowProfileRequest;
use Orbit\Sdk\Laravel\Responses\Profile\ProfileResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to GET /api/profile with target query data', function (): void {
    $request = new ShowProfileRequest(
        target: 'docs',
        uri: '/login',
        authMode: 'user',
        user: '42',
    );

    expect($request->resolveEndpoint())->toBe('/api/profile');
    expect($request->getMethod())->toBe(Method::GET);
    expect($request->query()->all())->toBe([
        'target' => 'docs',
        'uri' => '/login',
        'auth_mode' => 'user',
        'user' => '42',
    ]);
});

it('returns a ProfileResponse DTO with profile data', function (): void {
    $mock = new MockClient([
        ShowProfileRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'origin' => 'gateway',
                    'request' => ['url' => 'https://docs.test/'],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new ShowProfileRequest('docs'))->dto();

    expect($dto)
        ->toBeInstanceOf(ProfileResponse::class)
        ->and($dto->data)
        ->toBe([
            'origin' => 'gateway',
            'request' => ['url' => 'https://docs.test/'],
        ]);
});
