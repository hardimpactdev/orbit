<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Clusters\SetClusterRouterRequest;
use Orbit\Sdk\Requests\Clusters\UnsetClusterRouterRequest;
use Orbit\Sdk\Requests\Routes\CreateRouteRequest;
use Orbit\Sdk\Requests\Routes\DestroyRouteRequest;
use Orbit\Sdk\Requests\Routes\SetRouteTargetRequest;
use Orbit\Sdk\Requests\Routes\UnsetRouteTargetRequest;
use Orbit\Sdk\Requests\Routes\UpdateRouteRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('preserves Route and Router write retry keys and decodes the admitted Operation', function (Closure $makeRequest, string $kind): void {
    $request = $makeRequest();
    $mock = new MockClient([$request::class => MockResponse::make([
        'data' => ['id' => 41, 'kind' => $kind, 'target_type' => 'route', 'target_id' => 7,
            'input' => [], 'status' => 'queued', 'sequence' => 1],
        'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
    ], 202)]);
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient($mock);
    $operation = $connector->send($request)->dto();

    expect($operation)->toBeInstanceOf(OperationResponse::class)
        ->and($operation->id)->toBe(41)->and($operation->kind)->toBe($kind)
        ->and($operation->status)->toBe('queued')
        ->and($operation->requestId)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($mock->getLastPendingRequest()?->headers()->get('Idempotency-Key'))->toBe('route-proof');
})->with([
    'create' => [fn () => new CreateRouteRequest('dev.example.test', instanceId: 4, idempotencyKey: 'route-proof'), 'route.create'],
    'update' => [fn () => new UpdateRouteRequest(7, domain: 'next.example.test', idempotencyKey: 'route-proof'), 'route.update'],
    'destroy' => [fn () => new DestroyRouteRequest(7, offline: true, idempotencyKey: 'route-proof'), 'route.destroy'],
    'set target' => [fn () => new SetRouteTargetRequest(7, 4, idempotencyKey: 'route-proof'), 'route.target_set'],
    'unset target' => [fn () => new UnsetRouteTargetRequest(7, idempotencyKey: 'route-proof'), 'route.target_unset'],
    'set router' => [fn () => new SetClusterRouterRequest(2, 3, idempotencyKey: 'route-proof'), 'cluster.router_set'],
    'unset router' => [fn () => new UnsetClusterRouterRequest(2, true, idempotencyKey: 'route-proof'), 'cluster.router_unset'],
]);

it('keeps explicit web-root clearing and ordered target dispositions unchanged at admission', function (): void {
    expect((new UpdateRouteRequest(7, clearWebRoot: true))->body()->all())->toBe(['web_root' => null])
        ->and((new SetRouteTargetRequest(7, targetIds: [9, 4], dispositions: [['instance_id' => 12, 'remove' => true]]))->body()->all())
        ->toBe(['targets' => [9, 4], 'dispositions' => [['instance_id' => 12, 'remove' => true]]]);
});
