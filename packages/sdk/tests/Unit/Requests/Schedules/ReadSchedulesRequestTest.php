<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Schedules;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Schedules\AddScheduleRequest;
use Orbit\Sdk\Laravel\Requests\Schedules\ListSchedulesRequest;
use Orbit\Sdk\Laravel\Requests\Schedules\RemoveScheduleRequest;
use Orbit\Sdk\Laravel\Requests\Schedules\RunScheduleRequest;
use Orbit\Sdk\Laravel\Requests\Schedules\ShowScheduleLogsRequest;
use Orbit\Sdk\Laravel\Requests\Schedules\ShowScheduleRequest;
use Orbit\Sdk\Laravel\Responses\Schedules\ScheduleListResponse;
use Orbit\Sdk\Laravel\Responses\Schedules\ScheduleShowResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('posts schedule add payloads to the gateway', function (): void {
    $request = new AddScheduleRequest(
        name: 'laravel-scheduler',
        instance: 'docs.production',
        node: null,
        interval: 'every minute',
        timezone: 'UTC',
        command: 'php artisan schedule:run',
        script: null,
    );

    expect($request->resolveEndpoint())->toBe('/api/schedules');
    expect($request->getMethod())->toBe(Method::POST);
    expect($request->body()->all())->toBe([
        'name' => 'laravel-scheduler',
        'instance' => 'docs.production',
        'interval' => 'every minute',
        'timezone' => 'UTC',
        'command' => 'php artisan schedule:run',
    ]);
});

it('resolves schedule read endpoints and query filters', function (): void {
    $list = new ListSchedulesRequest(instance: 'docs.production', node: null);
    $show = new ShowScheduleRequest(name: 'laravel-scheduler', instance: 'docs.production', node: null);

    expect($list->resolveEndpoint())->toBe('/api/schedules');
    expect($list->getMethod())->toBe(Method::GET);
    expect($list->query()->all())->toBe(['instance' => 'docs.production']);
    expect($show->resolveEndpoint())->toBe('/api/schedules/laravel-scheduler');
    expect($show->getMethod())->toBe(Method::GET);
    expect($show->query()->all())->toBe(['instance' => 'docs.production']);
});

it('resolves schedule remove endpoint and query filters', function (): void {
    $request = new RemoveScheduleRequest(name: 'laravel-scheduler', instance: 'docs.production');

    expect($request->resolveEndpoint())->toBe('/api/schedules/laravel-scheduler');
    expect($request->getMethod())->toBe(Method::DELETE);
    expect($request->query()->all())->toBe(['instance' => 'docs.production']);
    expect($request->body()->all())->toBe([
        'destructive_consent' => true,
        'destructive_consent_source' => 'force',
    ]);
});

it('resolves manual schedule run endpoint and query filters', function (): void {
    $request = new RunScheduleRequest(name: 'laravel-scheduler', instance: 'docs.production');

    expect($request->resolveEndpoint())->toBe('/api/schedules/laravel-scheduler/run');
    expect($request->getMethod())->toBe(Method::POST);
    expect($request->query()->all())->toBe(['instance' => 'docs.production']);
});

it('resolves schedule logs endpoint and query filters', function (): void {
    $request = new ShowScheduleLogsRequest(
        name: 'laravel-scheduler',
        instance: 'docs.production',
        run: 18,
        lines: 10,
    );

    expect($request->resolveEndpoint())->toBe('/api/schedules/laravel-scheduler/logs');
    expect($request->getMethod())->toBe(Method::GET);
    expect($request->query()->all())->toBe(['instance' => 'docs.production', 'run' => 18, 'lines' => 10]);
});

it('returns schedule list and show response DTOs with meta', function (): void {
    $mock = new MockClient([
        ListSchedulesRequest::class => new MockResponse([
            'success' => [
                'data' => [
                    'schedules' => [
                        ['name' => 'laravel-scheduler'],
                    ],
                ],
                'meta' => ['instance' => 'docs.production', 'node' => null, 'count' => 1],
            ],
        ], 200),
        ShowScheduleRequest::class => new MockResponse([
            'success' => [
                'data' => [
                    'schedule' => ['name' => 'laravel-scheduler'],
                ],
                'meta' => ['instance' => 'docs.production', 'node' => null],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    /** @var ScheduleListResponse $listDto */
    $listDto = $connector->send(new ListSchedulesRequest(instance: 'docs.production'))->dto();
    /** @var ScheduleShowResponse $showDto */
    $showDto = $connector->send(new ShowScheduleRequest(name: 'laravel-scheduler', instance: 'docs.production'))->dto();

    expect($listDto)->toBeInstanceOf(ScheduleListResponse::class);
    expect($listDto->schedules)->toBe([['name' => 'laravel-scheduler']]);
    expect($listDto->meta)->toBe(['instance' => 'docs.production', 'node' => null, 'count' => 1]);
    expect($showDto)->toBeInstanceOf(ScheduleShowResponse::class);
    expect($showDto->schedule)->toBe(['name' => 'laravel-scheduler']);
    expect($showDto->meta)->toBe(['instance' => 'docs.production', 'node' => null]);
});
