<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Gateway\ShowGatewayStatusRequest;
use Orbit\Sdk\Responses\Gateway\GatewayStatusResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(ShowGatewayStatusRequest::class, function (): void {
    it('maps the versioned status endpoint to a typed response', function (): void {
        expect(class_exists(ShowGatewayStatusRequest::class))->toBeTrue();

        $mockClient = new MockClient([
            ShowGatewayStatusRequest::class => MockResponse::make([
                'data' => [
                    'name' => 'orbit-gateway',
                    'status' => 'ok',
                    'version' => '0.1.0',
                    'php_version' => '8.5.8',
                    'laravel_version' => '13.26.1',
                ],
                'meta' => [
                    'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
                ],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mockClient);

        $response = $connector->send(new ShowGatewayStatusRequest)->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->resolveEndpoint())
            ->toBe('/api/v1/gateway/status')
            ->and($request?->getMethod())
            ->toBe(Method::GET)
            ->and($response)
            ->toBeInstanceOf(GatewayStatusResponse::class)
            ->and($response->name)
            ->toBe('orbit-gateway')
            ->and($response->status)
            ->toBe('ok')
            ->and($response->version)
            ->toBe('0.1.0')
            ->and($response->phpVersion)
            ->toBe('8.5.8')
            ->and($response->laravelVersion)
            ->toBe('13.26.1')
            ->and($response->requestId)
            ->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844');
    });

    it('rejects non-string status fields instead of coercing them', function (): void {
        $credential = substr(hash('sha256', __METHOD__), offset: 0, length: 20);
        $mockClient = new MockClient([
            ShowGatewayStatusRequest::class => MockResponse::make([
                'data' => [
                    'name' => ['token' => $credential],
                    'status' => 503,
                    'version' => true,
                    'php_version' => null,
                    'laravel_version' => [],
                ],
                'meta' => [
                    'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
                ],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mockClient);

        $response = $connector->send(new ShowGatewayStatusRequest)->dto();

        expect($response)->toBeInstanceOf(GatewayStatusResponse::class);
        expect($response->toArray())->toBe([
            'name' => '',
            'status' => '',
            'version' => '',
            'php_version' => '',
            'laravel_version' => '',
            'desired_fleet_state' => null,
            'release' => null,
            'release_sha' => null,
            'auto_release' => null,
            'doctor' => null,
            'reconcile' => null,
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ]);
    });
});

it('preserves the grouped Doctor field from the recorded Gateway response', function (): void {
    $fixture = json_decode(file_get_contents(__DIR__.'/../../../../fixtures/gateway/gateway-status/doctor.json'), true, flags: JSON_THROW_ON_ERROR);
    $connector = new GatewayConnector('https://10.70.0.1');
    $connector->withMockClient(new MockClient([ShowGatewayStatusRequest::class => MockResponse::make($fixture['body'])]));
    $response = $connector->send(new ShowGatewayStatusRequest)->dto();
    expect($response->doctor)->toBe($fixture['body']['data']['doctor'])->and($response->toArray()['doctor'])->toBe($response->doctor)
        ->and($response->doctor['hidden_nodes'])->toBe(0)->and($response->doctor['last_run_ms'])->toBe(650)
        ->and($response->doctor['issues'][0]['count'])->toBe(2)->and($response->doctor['issues'][0]['resource'])->toBeNull()
        ->and($response->doctor['issues'][1]['resource'])->toBe(['type' => 'process', 'id' => '24']);
});

it('does not turn malformed Doctor data into a healthy report', function (mixed $doctor): void {
    $connector = new GatewayConnector('https://10.70.0.1');
    $connector->withMockClient(new MockClient([ShowGatewayStatusRequest::class => MockResponse::make([
        'data' => ['doctor' => $doctor], 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ])]));
    expect($connector->send(new ShowGatewayStatusRequest)->dto()->doctor)->toBeNull();
})->with([
    'not an object' => [false],
    'missing timestamp' => [['issues' => []]],
    'bad timestamp type' => [['collected_at' => 12, 'issues' => []]],
    'negative hidden count' => [['collected_at' => null, 'last_run_ms' => null, 'hidden_nodes' => -1, 'issues' => []]],
    'string duration' => [['collected_at' => null, 'last_run_ms' => '650', 'hidden_nodes' => 0, 'issues' => []]],
    'negative duration' => [['collected_at' => null, 'last_run_ms' => -1, 'hidden_nodes' => 0, 'issues' => []]],
    'invalid group' => [['collected_at' => null, 'issues' => [['node_id' => '1']]]],
]);

it('preserves the hidden Node count and nullable or fractional duration', function (int|float|null $duration): void {
    $doctor = ['collected_at' => '2026-11-12T12:00:00Z', 'last_run_ms' => $duration, 'hidden_nodes' => 2, 'issues' => []];
    $connector = new GatewayConnector('https://10.70.0.1');
    $connector->withMockClient(new MockClient([ShowGatewayStatusRequest::class => MockResponse::make([
        'data' => ['doctor' => $doctor], 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ])]));
    expect($connector->send(new ShowGatewayStatusRequest)->dto()->doctor)->toBe($doctor);
})->with([null, 0, 650.25]);

it('maps reconcile facts from the recorded response and preserves nullable and fractional values', function (): void {
    $fixture = json_decode(file_get_contents(__DIR__.'/../../../../fixtures/gateway/gateway-status/doctor.json'), true, flags: JSON_THROW_ON_ERROR);
    $connector = new GatewayConnector('https://10.70.0.1');
    $connector->withMockClient(new MockClient([ShowGatewayStatusRequest::class => MockResponse::make($fixture['body'])]));

    $response = $connector->send(new ShowGatewayStatusRequest)->dto();

    expect($response->reconcile)->toBe(['finished_at' => '2026-11-12T12:00:00.000000Z', 'last_pass_ms' => 6.125, 'unsettled_count' => 0])
        ->and($response->toArray()['reconcile'])->toBe($response->reconcile);
});

it('maps an absent or malformed reconcile object to null', function (mixed $reconcile): void {
    $connector = new GatewayConnector('https://10.70.0.1');
    $connector->withMockClient(new MockClient([ShowGatewayStatusRequest::class => MockResponse::make([
        'data' => ['reconcile' => $reconcile], 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ])]));

    expect($connector->send(new ShowGatewayStatusRequest)->dto()->reconcile)->toBeNull();
})->with([
    'null' => [null],
    'not an object' => [false],
    'missing keys' => [[]],
    'invalid timestamp type' => [['finished_at' => [], 'last_pass_ms' => 0, 'unsettled_count' => 0]],
    'invalid duration type' => [['finished_at' => null, 'last_pass_ms' => '6.125', 'unsettled_count' => 0]],
    'invalid count type' => [['finished_at' => null, 'last_pass_ms' => null, 'unsettled_count' => '0']],
]);

it('preserves null and numeric reconcile durations', function (int|float|null $duration): void {
    $reconcile = ['finished_at' => null, 'last_pass_ms' => $duration, 'unsettled_count' => 0];
    $connector = new GatewayConnector('https://10.70.0.1');
    $connector->withMockClient(new MockClient([ShowGatewayStatusRequest::class => MockResponse::make([
        'data' => ['reconcile' => $reconcile], 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ])]));

    expect($connector->send(new ShowGatewayStatusRequest)->dto()->reconcile)->toBe($reconcile);
})->with([null, 0, 6.125]);
