<?php

declare(strict_types=1);

use Orbit\Sdk\Requests\Metrics\EnableMetricsExporterRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('parses the accepted metrics.exporter_enable Operation without a synchronous mutation response', function (): void {
    $mock = new MockClient([EnableMetricsExporterRequest::class => MockResponse::make([
        'data' => ['id' => 42, 'kind' => 'metrics.exporter_enable', 'target_type' => 'metrics', 'target_id' => 9,
            'input' => ['exporter_node_id' => 13], 'status' => 'queued', 'sequence' => 1],
        'meta' => ['request_id' => node_role_request_id()],
    ], 202)]);
    $request = new EnableMetricsExporterRequest(13);
    $response = node_role_gateway_connector($mock)->send($request)->dto();
    expect($request->getMethod())->toBe(Method::PUT)->and($request->resolveEndpoint())->toBe('/api/v1/metrics/exporters/13')
        ->and($response)->toBeInstanceOf(OperationResponse::class)->and($response->kind)->toBe('metrics.exporter_enable')
        ->and($response->input['exporter_node_id'])->toBe(13)->and($response->status)->toBe('queued');
});
