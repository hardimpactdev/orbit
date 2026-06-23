<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Activity;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Activity\ShowActivityRequest;
use Orbit\Sdk\Laravel\Responses\Activity\ActivityShowResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to GET /api/activity/{id}', function (): void {
    $request = new ShowActivityRequest(42);

    expect($request->resolveEndpoint())->toBe('/api/activity/42');
    expect($request->getMethod())->toBe(Method::GET);
});

it('returns an ActivityShowResponse DTO', function (): void {
    $mock = new MockClient([
        ShowActivityRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'activity' => [
                        'id' => 42,
                        'type' => 'node.created',
                    ],
                    'related' => [
                        ['id' => 41, 'type' => 'node.create_requested'],
                    ],
                ],
                'meta' => [
                    'related_count' => 1,
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new ShowActivityRequest(42))->dto();

    expect($dto)->toBeInstanceOf(ActivityShowResponse::class);
    expect($dto->activity['id'])->toBe(42);
    expect($dto->related)->toBe([
        ['id' => 41, 'type' => 'node.create_requested'],
    ]);
    expect($dto->meta['related_count'])->toBe(1);
});
