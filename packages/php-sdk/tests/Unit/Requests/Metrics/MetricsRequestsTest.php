<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Metrics\DisableMetricsExporterRequest;
use Orbit\Sdk\Requests\Metrics\DisableMetricsRequest;
use Orbit\Sdk\Requests\Metrics\EnableMetricsExporterRequest;
use Orbit\Sdk\Requests\Metrics\EnableMetricsRequest;
use Orbit\Sdk\Requests\Metrics\ResetMetricsCredentialsRequest;
use Orbit\Sdk\Requests\Metrics\ShowMetricsCredentialsRequest;
use Orbit\Sdk\Requests\Metrics\ShowMetricsStatusRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('exposes the seven metrics routes', function (string $class, Method $method, string $path): void {
    $request = match ($class) {
        EnableMetricsRequest::class => new $class(7),
        DisableMetricsRequest::class => new $class,
        EnableMetricsExporterRequest::class, DisableMetricsExporterRequest::class => new $class(7),
        default => new $class,
    };
    expect($request->getMethod())->toBe($method)->and($request->resolveEndpoint())->toBe($path);
})->with([
    [EnableMetricsRequest::class,           Method::POST,   '/api/v1/metrics'],
    [DisableMetricsRequest::class,          Method::DELETE, '/api/v1/metrics'],
    [ShowMetricsStatusRequest::class,       Method::GET,    '/api/v1/metrics/status'],
    [ShowMetricsCredentialsRequest::class,  Method::GET,    '/api/v1/metrics/credentials'],
    [ResetMetricsCredentialsRequest::class, Method::POST,   '/api/v1/metrics/credentials/reset'],
    [EnableMetricsExporterRequest::class,   Method::PUT,    '/api/v1/metrics/exporters/7'],
    [DisableMetricsExporterRequest::class,  Method::DELETE, '/api/v1/metrics/exporters/7'],
]);

it('sends enable and disable payloads', function (): void {
    expect(new EnableMetricsRequest(7)->body()->all())
        ->toBe(['node_id' => 7])
        ->and(new DisableMetricsRequest(force: true, purgeData: true)->body()->all())
        ->toBe(['force' => true, 'purge_data' => true]);
});

it('decodes exporter admission as a native Operation', function (string $class, string $kind): void {
    $request = new $class(7);
    $mock = new MockClient([$class => MockResponse::make(['data' => ['id' => 42, 'kind' => $kind, 'target_type' => 'metrics', 'target_id' => 3, 'status' => 'queued', 'sequence' => 1, 'steps' => []], 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a846']], 202)]);
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient($mock);
    $result = $connector->send($request)->dto();
    expect($result)->toBeInstanceOf(OperationResponse::class)->and($result->id)->toBe(42)->and($result->kind)->toBe($kind);
})->with([[EnableMetricsExporterRequest::class, 'metrics.exporter_enable'], [DisableMetricsExporterRequest::class, 'metrics.exporter_disable']]);

it('decodes role enable and disable admissions as Operations', function (string $class, string $kind): void {
    $request = $class === EnableMetricsRequest::class ? new EnableMetricsRequest(7) : new DisableMetricsRequest(force: true);
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient(new MockClient([$class => MockResponse::make(['data' => ['id' => 42, 'kind' => $kind, 'target_type' => 'metrics', 'target_id' => 7, 'status' => 'queued', 'sequence' => 1, 'steps' => []], 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a846']], 202)]));
    expect($connector->send($request)->dto())->toBeInstanceOf(OperationResponse::class)->kind->toBe($kind);
})->with([[EnableMetricsRequest::class, 'metrics.enable'], [DisableMetricsRequest::class, 'metrics.disable']]);

it('decodes a native credential reset admission', function (): void {
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient(new MockClient([ResetMetricsCredentialsRequest::class => MockResponse::make(['data' => ['id' => 42, 'kind' => 'metrics.credentials_reset', 'target_type' => 'metrics', 'target_id' => 7, 'status' => 'queued', 'sequence' => 1, 'steps' => []], 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a846']], 202)]));
    expect($connector->send(new ResetMetricsCredentialsRequest)->dto())->toBeInstanceOf(OperationResponse::class)->kind->toBe('metrics.credentials_reset');
});
