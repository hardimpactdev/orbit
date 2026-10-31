<?php

declare(strict_types=1);

use Orbit\Sdk\Requests\Metrics\DisableMetricsRequest;
use Orbit\Sdk\Requests\Metrics\EnableMetricsRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('parses the accepted native Metrics role Operation and preserves its admission payload', function (string $requestClass, string $kind, Method $method, array $body): void {
    $requestId = node_role_request_id();
    $operation = ['id' => 42, 'kind' => $kind, 'target_type' => 'node', 'target_id' => 13,
        'input' => ['role' => 'metrics'], 'status' => 'queued', 'sequence' => 1];
    $mock = new MockClient([$requestClass => MockResponse::make([
        'data' => $operation, 'meta' => ['request_id' => $requestId],
    ], 202)]);
    $request = $requestClass === EnableMetricsRequest::class
        ? new EnableMetricsRequest(13)
        : new DisableMetricsRequest(force: true, purgeData: true);

    $response = node_role_gateway_connector($mock)->send($request)->dto();

    expect($request->getMethod())->toBe($method)
        ->and($request->resolveEndpoint())->toBe('/api/v1/metrics')
        ->and($request->body()->all())->toBe($body)
        ->and($response)->toBeInstanceOf(OperationResponse::class)
        ->and($response->id)->toBe(42)
        ->and($response->kind)->toBe($kind)
        ->and($response->targetType)->toBe('node')
        ->and($response->targetId)->toBe(13)
        ->and($response->input)->toBe(['role' => 'metrics'])
        ->and($response->status)->toBe('queued')
        ->and($response->terminal())->toBeFalse()
        ->and($response->requestId)->toBe($requestId);
})->with([
    'enable' => [EnableMetricsRequest::class, 'node.role_add', Method::POST, ['node_id' => 13]],
    'disable and purge' => [DisableMetricsRequest::class, 'node.role_remove', Method::DELETE, ['force' => true, 'purge_data' => true]],
]);
