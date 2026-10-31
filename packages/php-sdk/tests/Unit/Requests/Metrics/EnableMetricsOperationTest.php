<?php

declare(strict_types=1);

use Orbit\Sdk\Requests\Metrics\EnableMetricsRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('parses the accepted native Metrics role Operation and preserves its admission payload', function (): void {
    $requestId = node_role_request_id();
    $operation = ['id' => 42, 'kind' => 'node.role_add', 'target_type' => 'node', 'target_id' => 13,
        'input' => ['role' => 'metrics'], 'status' => 'queued', 'sequence' => 1];
    $mock = new MockClient([EnableMetricsRequest::class => MockResponse::make([
        'data' => $operation, 'meta' => ['request_id' => $requestId],
    ], 202)]);
    $request = new EnableMetricsRequest(13);

    $response = node_role_gateway_connector($mock)->send($request)->dto();

    expect($request->getMethod())->toBe(Method::POST)
        ->and($request->resolveEndpoint())->toBe('/api/v1/metrics')
        ->and($request->body()->all())->toBe(['node_id' => 13])
        ->and($response)->toBeInstanceOf(OperationResponse::class)
        ->and($response->id)->toBe(42)
        ->and($response->kind)->toBe('node.role_add')
        ->and($response->targetType)->toBe('node')
        ->and($response->targetId)->toBe(13)
        ->and($response->input)->toBe(['role' => 'metrics'])
        ->and($response->status)->toBe('queued')
        ->and($response->terminal())->toBeFalse()
        ->and($response->requestId)->toBe($requestId);
});
