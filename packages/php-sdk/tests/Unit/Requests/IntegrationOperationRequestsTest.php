<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\AiRouter\SetupAiRouterRequest;
use Orbit\Sdk\Requests\AiRouter\TeardownAiRouterRequest;
use Orbit\Sdk\Requests\Analytics\DisableInstanceAnalyticsRequest;
use Orbit\Sdk\Requests\Analytics\EnableInstanceAnalyticsRequest;
use Orbit\Sdk\Requests\Analytics\UpdateAnalyticsRequest;
use Orbit\Sdk\Requests\Metrics\DisableMetricsExporterRequest;
use Orbit\Sdk\Requests\Metrics\DisableMetricsRequest;
use Orbit\Sdk\Requests\Metrics\EnableMetricsExporterRequest;
use Orbit\Sdk\Requests\Metrics\EnableMetricsRequest;
use Orbit\Sdk\Requests\Metrics\ResetMetricsCredentialsRequest;
use Orbit\Sdk\Requests\Vault\SetupVaultRequest;
use Orbit\Sdk\Requests\Vault\SyncVaultRequest;
use Orbit\Sdk\Requests\Vault\TeardownVaultRequest;
use Orbit\Sdk\Requests\WebSocket\CreateWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\DestroyWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\RotateWebSocketAppRequest;
use Orbit\Sdk\Requests\WebSocket\SyncWebSocketAppsRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('parses the integration Operation and forwards the exact caller retry key', function (string $kind): void {
    $key = 'stable-retry.123';
    $request = match ($kind) {
        'vault.setup' => new SetupVaultRequest('https://vault.orbit', 'client', 'client-secret-sentinel', 'password-sentinel', 'Orbit', 'Database', $key),
        'vault.sync' => new SyncVaultRequest($key),
        'vault.teardown' => new TeardownVaultRequest(false, $key),
        'ai_router.setup' => new SetupAiRouterRequest(7, 'redis', 'http://10.44.0.7:8317', 'management-key-sentinel', $key),
        'ai_router.teardown' => new TeardownAiRouterRequest($key),
        'analytics.instance_enable' => new EnableInstanceAnalyticsRequest(7, ['stats.example.test'], $key),
        'analytics.instance_disable' => new DisableInstanceAnalyticsRequest(7, $key),
        'analytics.update' => new UpdateAnalyticsRequest('3.3.0', $key),
        'metrics.enable' => new EnableMetricsRequest(7, $key),
        'metrics.disable' => new DisableMetricsRequest(true, false, $key),
        'metrics.exporter_enable' => new EnableMetricsExporterRequest(7, $key),
        'metrics.exporter_disable' => new DisableMetricsExporterRequest(7, $key),
        'metrics.credentials_reset' => new ResetMetricsCredentialsRequest($key),
        'websocket.app_create' => new CreateWebSocketAppRequest('dev-proof', $key),
        'websocket.app_destroy' => new DestroyWebSocketAppRequest('dev-proof', $key),
        'websocket.app_rotate' => new RotateWebSocketAppRequest('dev-proof', $key),
        'websocket.sync' => new SyncWebSocketAppsRequest($key),
    };
    $mock = new MockClient([$request::class => MockResponse::make([
        'data' => ['id' => 12, 'kind' => $kind, 'target_type' => 'integration', 'target_id' => 7, 'input' => [], 'status' => 'queued', 'sequence' => 1],
        'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
    ], 202)]);
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient($mock);
    $response = $connector->send($request)->dto();
    expect($response)->toBeInstanceOf(OperationResponse::class)->and($response->kind)->toBe($kind)->and($response->status)->toBe('queued')
        ->and($mock->getLastPendingRequest()?->headers()->get('Idempotency-Key'))->toBe($key);
    expect(print_r($request, true))->not->toContain('client-secret-sentinel', 'password-sentinel', 'management-key-sentinel');
})->with(['vault.setup', 'vault.sync', 'vault.teardown', 'ai_router.setup', 'ai_router.teardown', 'analytics.update', 'analytics.instance_enable', 'analytics.instance_disable', 'metrics.enable', 'metrics.disable', 'metrics.exporter_enable', 'metrics.exporter_disable', 'metrics.credentials_reset', 'websocket.app_create', 'websocket.app_destroy', 'websocket.app_rotate', 'websocket.sync']);
