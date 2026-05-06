<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Schedules;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Schedules\StoreSchedulerHeartbeatRequest;
use App\Http\Gateway\Responses\Schedules\SchedulerHeartbeatResponse;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $settings = LocalGatewaySettings::current();
    $settings->gateway_url = 'https://10.6.0.2';
    $settings->ca_pem_path = '/path/to/ca.pem';
    $settings->save();
});

it('posts scheduler heartbeat state to the gateway endpoint', function (): void {
    $request = new StoreSchedulerHeartbeatRequest(
        heartbeatAt: '2026-05-06T12:34:00Z',
        registrySyncedAt: '2026-05-06T12:33:55Z',
    );

    expect($request->resolveEndpoint())->toBe('/api/schedules/heartbeat');
    expect($request->getMethod())->toBe(Method::POST);
    expect($request->body()->all())->toBe([
        'heartbeat_at' => '2026-05-06T12:34:00Z',
        'registry_synced_at' => '2026-05-06T12:33:55Z',
    ]);
});

it('returns a scheduler heartbeat response DTO', function (): void {
    $mock = new MockClient([
        StoreSchedulerHeartbeatRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'state' => [
                        'node' => 'app-1',
                        'heartbeat_at' => '2026-05-06T12:34:00+00:00',
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = GatewayConnector::forScheduler();
    $connector->withMockClient($mock);

    $dto = $connector->send(new StoreSchedulerHeartbeatRequest(
        heartbeatAt: '2026-05-06T12:34:00Z',
    ))->dto();

    expect($dto)->toBeInstanceOf(SchedulerHeartbeatResponse::class);
    expect($dto->state)->toBe([
        'node' => 'app-1',
        'heartbeat_at' => '2026-05-06T12:34:00+00:00',
    ]);
});
