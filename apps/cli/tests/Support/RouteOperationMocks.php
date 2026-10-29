<?php

declare(strict_types=1);

use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Orbit\Sdk\Requests\Routes\CreateRouteRequest;
use Orbit\Sdk\Requests\Routes\DestroyRouteRequest;
use Orbit\Sdk\Requests\Routes\SetRouteTargetRequest;
use Orbit\Sdk\Requests\Routes\UnsetRouteTargetRequest;
use Orbit\Sdk\Requests\Routes\UpdateRouteRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

/** Keeps the old argument cases but supplies the new admitted and final Operation envelopes. */
function route_operation_mock(array $responses = []): MockClient
{
    $last = null;
    foreach ([CreateRouteRequest::class => 'route.create', UpdateRouteRequest::class => 'route.update', DestroyRouteRequest::class => 'route.destroy', SetRouteTargetRequest::class => 'route.target_set', UnsetRouteTargetRequest::class => 'route.target_unset'] as $class => $kind) {
        $response = $responses[$class] ?? null;
        if (! $response instanceof MockResponse || $response->status() >= 400) {
            continue;
        }
        $payload = $response->body()->all();
        $route = $payload['data'];
        $responses[$class] = static function () use ($payload, $route, $kind, &$last): MockResponse {
            $last = ['id' => 42, 'kind' => $kind, 'target_type' => 'route', 'target_id' => $route['id'], 'input' => [], 'status' => 'succeeded', 'sequence' => 5,
                'result' => ['ready' => true, 'route_id' => $route['id'], 'retained_on_nodes' => $route['retained_on_nodes'] ?? []]];

            return MockResponse::make(['data' => $last, 'meta' => $payload['meta']], 202);
        };
    }
    $responses[ShowOperationRequest::class] = static function () use (&$last): MockResponse {
        return MockResponse::make(['data' => $last, 'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111']]);
    };
    $responses[ShowRealtimeRequest::class] = MockResponse::make([], 503);

    return MockClient::global($responses);
}

function route_last_mutation(MockClient $mock): ?Request
{
    foreach (array_reverse($mock->getRecordedResponses()) as $response) {
        $request = $response->getRequest();
        if (! $request instanceof ShowOperationRequest && ! $request instanceof ShowRealtimeRequest) {
            return $request;
        }
    }

    return null;
}
