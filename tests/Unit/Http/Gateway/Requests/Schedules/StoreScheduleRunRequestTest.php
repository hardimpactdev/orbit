<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Gateway\Requests\Schedules;

use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Schedules\StoreScheduleRunRequest;
use App\Http\Gateway\Responses\Schedules\ScheduleRunResponse;
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

it('posts schedule run history to the gateway intake endpoint', function (): void {
    $request = new StoreScheduleRunRequest(
        scheduleKey: 'app:docs:laravel-scheduler',
        status: 'completed',
        exitCode: 0,
        stdout: "ok\n",
        stderr: '',
        startedAt: '2026-05-06T12:34:00Z',
        finishedAt: '2026-05-06T12:34:03Z',
    );

    expect($request->resolveEndpoint())->toBe('/api/schedules/runs');
    expect($request->getMethod())->toBe(Method::POST);
    expect($request->body()->all())->toBe([
        'schedule_key' => 'app:docs:laravel-scheduler',
        'status' => 'completed',
        'exit_code' => 0,
        'stdout' => "ok\n",
        'stderr' => '',
        'started_at' => '2026-05-06T12:34:00Z',
        'finished_at' => '2026-05-06T12:34:03Z',
    ]);
});

it('returns a schedule run response DTO', function (): void {
    $mock = new MockClient([
        StoreScheduleRunRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'run' => [
                        'id' => 12,
                        'schedule_key' => 'app:docs:laravel-scheduler',
                    ],
                ],
            ],
        ], 201),
    ]);

    $connector = GatewayConnector::forScheduler();
    $connector->withMockClient($mock);

    $dto = $connector->send(new StoreScheduleRunRequest(
        scheduleKey: 'app:docs:laravel-scheduler',
        status: 'completed',
        exitCode: 0,
        stdout: '',
        stderr: '',
        startedAt: '2026-05-06T12:34:00Z',
        finishedAt: '2026-05-06T12:34:03Z',
    ))->dto();

    expect($dto)->toBeInstanceOf(ScheduleRunResponse::class);
    expect($dto->run)->toBe([
        'id' => 12,
        'schedule_key' => 'app:docs:laravel-scheduler',
    ]);
});
