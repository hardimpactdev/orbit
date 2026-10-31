<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Metrics\DisableMetricsExporterRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('follows the metrics.exporter_disable Operation and renders its terminal result', function (): void {
    MockClient::destroyGlobal();
    $home = sys_get_temp_dir().'/orbit-exporter-operation-'.Str::uuid();
    config()->set('orbit.home', $home);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(name: 'test', url: 'https://10.44.0.1', caPath: '/fixture/ca.pem'));
    $requestId = (string) Str::uuid();
    $operation = ['id' => 42, 'kind' => 'metrics.exporter_disable', 'target_type' => 'metrics', 'target_id' => 9,
        'input' => ['exporter_node_id' => 13], 'status' => 'queued', 'sequence' => 1, 'poll_after_seconds' => 0];
    $mock = MockClient::global([
        DisableMetricsExporterRequest::class => MockResponse::make(['data' => $operation, 'meta' => ['request_id' => $requestId]], 202),
        ShowOperationRequest::class => MockResponse::make(['data' => [...$operation, 'status' => 'succeeded', 'sequence' => 2, 'result' => ['ready' => true]], 'meta' => ['request_id' => $requestId]]),
        ShowRealtimeRequest::class => MockResponse::make(['data' => ['enabled' => false]]),
    ]);
    try {
        $this->artisan('metrics:exporter:disable', ['node' => '13', '--json' => true])
            ->expectsOutputToContain('"ready":true')->assertSuccessful();
        $mock->assertSent(ShowOperationRequest::class);
    } finally {
        MockClient::destroyGlobal();
        new Filesystem()->deleteDirectory($home);
    }
});
