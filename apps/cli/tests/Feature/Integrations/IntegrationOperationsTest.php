<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\AiRouter\TeardownAiRouterRequest;
use Orbit\Sdk\Requests\Analytics\UpdateAnalyticsRequest;
use Orbit\Sdk\Requests\Metrics\EnableMetricsExporterRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Orbit\Sdk\Requests\Vault\SyncVaultRequest;
use Orbit\Sdk\Requests\WebSocket\CreateWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\SyncWebSocketAppsRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->integrationHome = sys_get_temp_dir().'/orbit-cli-integration-'.Str::uuid();
    config()->set('orbit.home', $this->integrationHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://gateway.example.test', '/fixture-ca.pem'));
});
afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->integrationHome);
});
it('follows each integration write and forwards the retry key', function (string $command, string $kind, string $requestClass, array $arguments): void {
    $id = '11111111-1111-4111-8111-111111111111';
    $base = ['id' => 12, 'kind' => $kind, 'target_type' => 'integration', 'target_id' => 7, 'input' => [], 'sequence' => 1, 'poll_after_seconds' => 0];
    $final = [...$base, 'status' => 'succeeded', 'result' => ['backend' => 'active']];
    $mock = MockClient::global([
        $requestClass => MockResponse::make(['data' => [...$base, 'status' => 'queued', 'result' => null], 'meta' => ['request_id' => $id]], 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => MockResponse::make(['data' => $final, 'meta' => ['request_id' => $id]]),
    ]);
    $option = str_starts_with($kind, 'websocket.') ? '--operation-key' : '--idempotency-key';
    $this->artisan($command, [...$arguments, $option => 'integration-retry', '--json' => true])
        ->expectsOutput(json_encode(OperationResponse::fromGatewayData($final, $id)->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))->assertExitCode(0);
    $mock->assertSent(fn ($request): bool => $request instanceof $requestClass && $request->headers()->get('Idempotency-Key') === 'integration-retry');
    $mock->assertSent(fn ($request): bool => $request instanceof ShowOperationRequest);
})->with([
    ['vault:sync', 'vault.sync', SyncVaultRequest::class, []],
    ['ai-router:teardown', 'ai_router.teardown', TeardownAiRouterRequest::class, ['--yes' => true]],
    ['analytics:update', 'analytics.update', UpdateAnalyticsRequest::class, ['version' => '3.3.0']],
    ['metrics:exporter:enable', 'metrics.exporter_enable', EnableMetricsExporterRequest::class, ['node' => '7']],
    ['websocket:app:create', 'websocket.app_create', CreateWebSocketAppRequest::class, ['name' => 'dev-proof']],
    ['websocket:sync', 'websocket.sync', SyncWebSocketAppsRequest::class, []],
]);

it('keeps websocket sync bounded and returns the admitted Operation when wait is zero', function (): void {
    $id = '11111111-1111-4111-8111-111111111111';
    $data = ['id' => 12, 'kind' => 'websocket.sync', 'target_type' => 'websocket_registry', 'target_id' => 1,
        'input' => [], 'sequence' => 1, 'poll_after_seconds' => 0, 'status' => 'queued', 'result' => null];
    $mock = MockClient::global([SyncWebSocketAppsRequest::class => MockResponse::make(['data' => $data, 'meta' => ['request_id' => $id]], 202)]);
    $this->artisan('websocket:sync', ['--wait' => 0, '--json' => true])
        ->expectsOutput(json_encode(OperationResponse::fromGatewayData($data, $id)->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))->assertExitCode(1);
    $mock->assertNotSent(fn ($request): bool => $request instanceof ShowOperationRequest);
});

it('validates websocket sync wait before admitting an Operation', function (): void {
    $mock = MockClient::global([]);
    $this->artisan('websocket:sync', ['--wait' => -1, '--json' => true])->assertExitCode(1);
    $mock->assertNothingSent();
});
