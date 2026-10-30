<?php

declare(strict_types=1);

use Orbit\Sdk\Requests\Analytics\UpdateAnalyticsRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(UpdateAnalyticsRequest::class, function (): void {
    it('posts the version and returns the typed update response', function (): void {
        $mockClient = new MockClient([
            UpdateAnalyticsRequest::class => MockResponse::make([
                'data' => ['id' => 42, 'kind' => 'analytics.update', 'target_type' => 'node', 'target_id' => 17, 'input' => [], 'status' => 'succeeded', 'sequence' => 3, 'current_step' => null, 'failed_step' => null, 'error_code' => null, 'result' => ['ready' => true, 'version' => '3.3.0', 'previous_version' => '3.2.1'], 'cancel_requested' => false, 'attempts' => 0, 'triggered_by' => 'operator', 'created_at' => '2026-10-30T10:00:00Z', 'started_at' => '2026-10-30T10:00:01Z', 'finished_at' => '2026-10-30T10:00:02Z', 'poll_after_seconds' => 2],
                'meta' => ['request_id' => node_role_request_id()],
            ], 202),
        ]);
        $request = new UpdateAnalyticsRequest('3.3.0');

        $response = node_role_gateway_connector($mockClient)->send($request)->dto();

        expect($request->getMethod())->toBe(Method::POST)
            ->and($request->resolveEndpoint())->toBe('/api/v1/analytics/update')
            ->and($mockClient->getLastPendingRequest()?->body()->all())->toBe(['version' => '3.3.0'])
            ->and($response)->toBeInstanceOf(OperationResponse::class)
            ->and($response->result)->toBe(['ready' => true, 'version' => '3.3.0', 'previous_version' => '3.2.1']);
    });

});
