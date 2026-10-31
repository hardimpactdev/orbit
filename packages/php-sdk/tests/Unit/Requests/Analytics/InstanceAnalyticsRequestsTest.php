<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\Analytics\DisableInstanceAnalyticsRequest;
use Orbit\Sdk\Requests\Analytics\EnableInstanceAnalyticsRequest;
use Orbit\Sdk\Requests\Analytics\ShowInstanceAnalyticsRequest;
use Orbit\Sdk\Responses\Analytics\InstanceAnalyticsResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/** @return array<string, mixed> */
function instance_analytics_gateway_data(): array
{
    return [
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
    ];
}

describe('the instance analytics requests', function (): void {
    it('addresses one App instance with the right method and no body for show and disable', function (string $class, Method $method): void {
        $request = new $class(12);

        expect($request->getMethod())->toBe($method)
            ->and($request->resolveEndpoint())->toBe('/api/v1/instances/12/analytics');
    })->with([
        'show' => [ShowInstanceAnalyticsRequest::class, Method::GET],
        'disable' => [DisableInstanceAnalyticsRequest::class, Method::DELETE],
    ]);

    it('sends the exact host set on enable, and no hosts key for the default host', function (): void {
        $explicit = new EnableInstanceAnalyticsRequest(12, ['stats.example.com', 'analytics.shop.example.com']);
        $default = new EnableInstanceAnalyticsRequest(12);

        expect($explicit->getMethod())->toBe(Method::POST)
            ->and($explicit->resolveEndpoint())->toBe('/api/v1/instances/12/analytics')
            ->and($explicit->body()->all())->toBe(['hosts' => ['stats.example.com', 'analytics.shop.example.com']])
            ->and($default->body()->all())->toBe([]);
    });

    it('returns the typed response with every host, its DNS record, and the snippet', function (): void {
        $mockClient = new MockClient([
            ShowInstanceAnalyticsRequest::class => MockResponse::make([
                'data' => instance_analytics_gateway_data(),
                'meta' => ['request_id' => node_role_request_id()],
            ]),
        ]);

        $response = node_role_gateway_connector($mockClient)->send(new ShowInstanceAnalyticsRequest(12))->dto();

        expect($response)->toBeInstanceOf(InstanceAnalyticsResponse::class)
            ->and($response->toArray())->toBe([...instance_analytics_gateway_data(), 'request_id' => node_role_request_id()])
            ->and($response->hosts[0]['dns'])->toBe(['type' => 'CNAME', 'name' => 'analytics.shop.example.com', 'value' => 'shop.example.com']);
    });

    it('returns the admitted native parent and preserves timezone and goals', function (): void {
        $goals = [['goal_type' => 'Goal.CustomEvent', 'goal' => ['event_name' => 'Signup']]];
        $request = new EnableInstanceAnalyticsRequest(12, [], 'UTC', $goals);
        $mock = new MockClient([EnableInstanceAnalyticsRequest::class => MockResponse::make([
            'data' => ['id' => 42, 'kind' => 'analytics.enable', 'target_type' => 'instance', 'target_id' => 12, 'status' => 'queued', 'sequence' => 1],
            'meta' => ['request_id' => node_role_request_id()],
        ], 202)]);
        $response = node_role_gateway_connector($mock)->send($request)->dto();
        expect($request->body()->all())->toBe(['timezone' => 'UTC', 'goals' => $goals])
            ->and($response)->toBeInstanceOf(OperationResponse::class)
            ->and($response->kind)->toBe('analytics.enable')->and($response->targetId)->toBe(12);
    });

    it('accepts an instance without tracking: no domain, no dashboard, no hosts, no snippet', function (): void {
        $mockClient = new MockClient([
            ShowInstanceAnalyticsRequest::class => MockResponse::make([
                'data' => ['instance_id' => 12, 'enabled' => false, 'domain' => null, 'dashboard_url' => null, 'hosts' => [], 'snippet' => null],
                'meta' => ['request_id' => node_role_request_id()],
            ]),
        ]);

        $response = node_role_gateway_connector($mockClient)->send(new ShowInstanceAnalyticsRequest(12))->dto();

        expect($response->enabled)->toBeFalse()->and($response->hosts)->toBe([])->and($response->snippet)->toBeNull();
    });

    it('accepts a host without a DNS record, for an App instance that has no domain', function (): void {
        $data = instance_analytics_gateway_data();
        $data['hosts'][0]['dns'] = null;
        $mockClient = new MockClient([
            ShowInstanceAnalyticsRequest::class => MockResponse::make(['data' => $data, 'meta' => ['request_id' => node_role_request_id()]]),
        ]);

        $response = node_role_gateway_connector($mockClient)->send(new ShowInstanceAnalyticsRequest(12))->dto();

        expect($response->hosts[0]['dns'])->toBeNull();
    });

    it('refuses an answer with a malformed host', function (array $broken): void {
        $data = instance_analytics_gateway_data();
        $data['hosts'][0] = [...$data['hosts'][0], ...$broken];
        $mockClient = new MockClient([
            ShowInstanceAnalyticsRequest::class => MockResponse::make(['data' => $data, 'meta' => ['request_id' => node_role_request_id()]]),
        ]);

        expect(fn () => node_role_gateway_connector($mockClient)->send(new ShowInstanceAnalyticsRequest(12))->dto())
            ->toThrow(GatewayApiException::class, 'invalid instance analytics data');
    })->with([
        'route id as text' => [['route_id' => '91']],
        'failed step as a number' => [['failed_step' => 1]],
        'error code as a number' => [['error_code' => 1]],
        'DNS record that is not an object' => [['dns' => 'CNAME analytics.example.com']],
        'DNS without a value' => [['dns' => ['type' => 'CNAME', 'name' => 'analytics.shop.example.com']]],
    ]);

    it('refuses an answer whose optional text is not text', function (string $field): void {
        $data = instance_analytics_gateway_data();
        $data[$field] = 12;
        $mockClient = new MockClient([
            ShowInstanceAnalyticsRequest::class => MockResponse::make(['data' => $data, 'meta' => ['request_id' => node_role_request_id()]]),
        ]);

        expect(fn () => node_role_gateway_connector($mockClient)->send(new ShowInstanceAnalyticsRequest(12))->dto())
            ->toThrow(GatewayApiException::class, 'invalid instance analytics data');
    })->with(['domain', 'dashboard_url', 'snippet']);
});

it('decodes asynchronous analytics receipts as Operations', function (string $class): void {
    $data = [
        'id' => 42, 'kind' => 'analytics.enable', 'target_type' => 'instance', 'target_id' => 12,
        'input' => [], 'status' => 'succeeded', 'sequence' => 3, 'current_step' => null,
        'failed_step' => null, 'error_code' => null, 'result' => ['ready' => true],
        'cancel_requested' => false, 'attempts' => 1, 'triggered_by' => 'operator',
        'created_at' => '2026-10-30T10:00:00+00:00', 'started_at' => '2026-10-30T10:00:01+00:00',
        'finished_at' => '2026-10-30T10:00:02+00:00', 'poll_after_seconds' => 2,
    ];
    $mock = new MockClient([$class => MockResponse::make(['data' => $data, 'meta' => ['request_id' => node_role_request_id()]], 202)]);
    $response = node_role_gateway_connector($mock)->send(new $class(12))->dto();
    expect($response)->toBeInstanceOf(OperationResponse::class)
        ->and($response->id)->toBe(42);
})->with([EnableInstanceAnalyticsRequest::class]);

it('transports a supplied timezone without changing the default body', function (): void {
    expect((new EnableInstanceAnalyticsRequest(12, timezone: 'UTC'))->body()->all())->toBe(['timezone' => 'UTC']);
});
