<?php

declare(strict_types=1);

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Activity\ListActivityRequest;
use Orbit\Sdk\Laravel\Responses\Activity\ActivityListResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);

it('targets the activity list endpoint with normalized query parameters', function (): void {
    $request = new ListActivityRequest(
        app: 'docs',
        node: 'app-1',
        effect: 'destructive',
        correlation: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        limit: 50,
    );

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('/api/activity')
        ->and($request->query()->all())
        ->toBe([
            'app' => 'docs',
            'node' => 'app-1',
            'effect' => 'destructive',
            'correlation' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'limit' => 50,
        ]);
});

it('creates an activity list dto from the gateway envelope', function (): void {
    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $request = new ListActivityRequest(effect: 'destructive');

    $mock = new MockClient([
        ListActivityRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'activities' => [
                        [
                            'id' => 42,
                            'effect' => 'destructive',
                        ],
                    ],
                ],
                'meta' => [
                    'count' => 1,
                    'has_more' => false,
                ],
            ],
        ], 200),
    ]);

    $connector->withMockClient($mock);

    $dto = $connector->send($request)->dto();

    expect($dto)
        ->toBeInstanceOf(ActivityListResponse::class)
        ->and($dto->activities)
        ->toBe([
            [
                'id' => 42,
                'effect' => 'destructive',
            ],
        ])
        ->and($dto->meta)
        ->toBe([
            'count' => 1,
            'has_more' => false,
        ]);
});
