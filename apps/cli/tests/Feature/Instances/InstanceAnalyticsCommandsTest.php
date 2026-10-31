<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Analytics\DisableInstanceAnalyticsRequest;
use Orbit\Sdk\Requests\Analytics\EnableInstanceAnalyticsRequest;
use Orbit\Sdk\Requests\Analytics\ShowInstanceAnalyticsRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-instance-analytics-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);

    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
    $this->requestId = (string) Str::uuid();
    $this->operation = fn (): MockResponse => MockResponse::make(['data' => [
        'id' => 42, 'kind' => 'analytics.enable', 'target_type' => 'instance', 'target_id' => 12,
        'input' => [], 'status' => 'succeeded', 'sequence' => 3, 'current_step' => null,
        'failed_step' => null, 'error_code' => null, 'result' => ['ready' => true],
        'cancel_requested' => false, 'attempts' => 1, 'triggered_by' => 'operator',
        'created_at' => '2026-10-30T10:00:00+00:00', 'started_at' => '2026-10-30T10:00:01+00:00',
        'finished_at' => '2026-10-30T10:00:02+00:00', 'poll_after_seconds' => 2,
    ], 'meta' => ['request_id' => $this->requestId]], 202);
    $this->enabled = fn (): MockResponse => MockResponse::make([
        'data' => [
            'instance_id' => 12,
            'enabled' => true,
            'domain' => 'shop.example.com',
            'dashboard_url' => 'https://analytics.orbit',
            'hosts' => [[
                'host' => 'analytics.shop.example.com',
                'route_id' => 91,
                'status' => 'active',
                'publication' => 'public',
                'failed_step' => null,
                'error_code' => null,
                'script_url' => 'https://analytics.shop.example.com/js/script.js',
                'event_url' => 'https://analytics.shop.example.com/api/event',
                'dns' => ['type' => 'CNAME', 'name' => 'analytics.shop.example.com', 'value' => 'shop.example.com'],
            ]],
            'snippet' => '<script defer data-domain="shop.example.com" src="https://analytics.shop.example.com/js/script.js"></script>',
        ],
        'meta' => ['request_id' => $this->requestId],
    ]);
    $this->disabled = fn (): MockResponse => MockResponse::make([
        'data' => ['instance_id' => 12, 'enabled' => false, 'domain' => 'shop.example.com', 'dashboard_url' => 'https://analytics.orbit', 'hosts' => [], 'snippet' => null],
        'meta' => ['request_id' => $this->requestId],
    ]);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('instance:analytics:enable', function (): void {
    it('publishes the default host when none is named, and tells the operator what to do next', function (): void {
        $mockClient = MockClient::global([
            EnableInstanceAnalyticsRequest::class => ($this->operation)(),
            ShowOperationRequest::class => ($this->operation)(),
            ShowRealtimeRequest::class => MockResponse::make(['data' => ['enabled' => false]]),
            ShowInstanceAnalyticsRequest::class => ($this->enabled)(),
        ]);

        $this->artisan('instance:analytics:enable', ['instance' => '12'])
            ->expectsOutputToContain('Analytics tracking enabled.')
            ->expectsOutputToContain('CNAME analytics.shop.example.com -> shop.example.com')
            ->expectsOutputToContain('<script defer data-domain="shop.example.com"')
            ->assertExitCode(0);

        $mockClient->assertSent(fn ($request): bool => $request instanceof EnableInstanceAnalyticsRequest && $request->body()->all() === []);
    });

    it('sends every named host', function (): void {
        $mockClient = MockClient::global([
            EnableInstanceAnalyticsRequest::class => ($this->operation)(),
            ShowOperationRequest::class => ($this->operation)(),
            ShowRealtimeRequest::class => MockResponse::make(['data' => ['enabled' => false]]),
            ShowInstanceAnalyticsRequest::class => ($this->enabled)(),
        ]);

        $this->artisan('instance:analytics:enable', ['instance' => '12', '--host' => ['stats.example.com', 'analytics.shop.example.com'], '--json' => true])
            ->assertExitCode(0);

        $mockClient->assertSent(fn ($request): bool => $request instanceof EnableInstanceAnalyticsRequest && $request->body()->all() === ['hosts' => ['stats.example.com', 'analytics.shop.example.com']]);
    });
});

describe('instance:analytics:show', function (): void {
    it('returns the Gateway answer as JSON', function (): void {
        MockClient::global([ShowInstanceAnalyticsRequest::class => ($this->enabled)()]);

        $this->artisan('instance:analytics:show', ['instance' => '12', '--json' => true])
            ->expectsOutputToContain('"script_url":"https://analytics.shop.example.com/js/script.js"')
            ->assertExitCode(0);
    });

    it('says tracking is disabled for an instance without hosts', function (): void {
        MockClient::global([ShowInstanceAnalyticsRequest::class => ($this->disabled)()]);

        $this->artisan('instance:analytics:show', ['instance' => '12'])
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);
    });
});

describe('instance:analytics:disable', function (): void {
    it('refuses without --yes when it cannot ask, and sends nothing', function (): void {
        $mockClient = MockClient::global();

        $this->artisan('instance:analytics:disable', ['instance' => '12', '--json' => true])
            ->expectsOutputToContain('input.confirmation_required')
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    });

    it('removes the hosts once confirmed', function (): void {
        $data = ($this->operation)()->body()->all();
        $data['data']['kind'] = 'analytics.disable';
        $mockClient = MockClient::global([
            DisableInstanceAnalyticsRequest::class => MockResponse::make($data, 202),
            ShowOperationRequest::class => MockResponse::make($data),
            ShowRealtimeRequest::class => MockResponse::make(['data' => ['enabled' => false]]),
            ShowInstanceAnalyticsRequest::class => ($this->disabled)(),
        ]);

        $this->artisan('instance:analytics:disable', ['instance' => '12', '--yes' => true])
            ->expectsOutputToContain('Analytics tracking disabled.')
            ->assertExitCode(0);

        $mockClient->assertSent(DisableInstanceAnalyticsRequest::class);
    });
});

it('refuses an instance that is not a positive number before any request', function (string $command): void {
    $mockClient = MockClient::global();

    $this->artisan($command, ['instance' => 'shop', '--json' => true])
        ->expectsOutputToContain('instance.id_invalid')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with(['instance:analytics:show', 'instance:analytics:enable', 'instance:analytics:disable']);

it('follows an analytics Operation and emits one machine result', function (string $command, string $class, array $options): void {
    $data = [
        'id' => 42, 'kind' => 'analytics.enable', 'target_type' => 'instance', 'target_id' => 12,
        'input' => [], 'status' => 'succeeded', 'sequence' => 3, 'current_step' => null,
        'failed_step' => null, 'error_code' => null, 'result' => ['ready' => true],
        'cancel_requested' => false, 'attempts' => 1, 'triggered_by' => 'operator',
        'created_at' => '2026-10-30T10:00:00+00:00', 'started_at' => '2026-10-30T10:00:01+00:00',
        'finished_at' => '2026-10-30T10:00:02+00:00', 'poll_after_seconds' => 2,
    ];
    MockClient::global([
        ShowRealtimeRequest::class => MockResponse::make(['data' => ['enabled' => false], 'meta' => ['request_id' => $this->requestId]]),
        $class => MockResponse::make(['data' => $data, 'meta' => ['request_id' => $this->requestId]], 202),
        ShowOperationRequest::class => MockResponse::make(['data' => $data, 'meta' => ['request_id' => $this->requestId]]),
    ]);
    $this->artisan($command, ['instance' => '12', '--json' => true, ...$options])
        ->expectsOutputToContain('"status":"succeeded"')
        ->doesntExpectOutputToContain('tracking enabled')
        ->assertExitCode(0);
})->with([
    ['instance:analytics:enable', EnableInstanceAnalyticsRequest::class, []],
]);
