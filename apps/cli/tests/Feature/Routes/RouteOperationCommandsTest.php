<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Orbit\Sdk\Requests\Clusters\SetClusterRouterRequest;
use Orbit\Sdk\Requests\Clusters\UnsetClusterRouterRequest;
use Orbit\Sdk\Requests\Clusters\ShowClusterRequest;
use Orbit\Sdk\Requests\Routes\CreateRouteRequest;
use Orbit\Sdk\Requests\Routes\DestroyRouteRequest;
use Orbit\Sdk\Requests\Routes\SetRouteTargetRequest;
use Orbit\Sdk\Requests\Routes\UnsetRouteTargetRequest;
use Orbit\Sdk\Requests\Routes\UpdateRouteRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-route-operation-'.bin2hex(random_bytes(8));
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(name: 'test', url: 'https://10.44.0.1', caPath: '/unused/root.pem'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('forwards the retry key and renders the final Route or Router Operation', function (string $command, array $arguments, string $requestClass, string $kind): void {
    $requestId = '11111111-1111-4111-8111-111111111111';
    $operation = ['id' => 42, 'kind' => $kind, 'target_type' => 'route', 'target_id' => 7, 'input' => [], 'status' => 'succeeded', 'sequence' => 5, 'result' => ['ready' => true]];
    $response = ['data' => $operation, 'meta' => ['request_id' => $requestId]];
    $mock = MockClient::global([
        $requestClass => MockResponse::make($response, 202),
        ShowClusterRequest::class => MockResponse::make(['data' => ['id' => 7, 'name' => 'dev', 'state' => 'inactive', 'nodes' => [], 'router' => null], 'meta' => ['request_id' => $requestId]]),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => MockResponse::make($response),
    ]);
    $this->artisan($command, [...$arguments, '--json' => true, '--idempotency-key' => 'route-proof'])
        ->expectsOutput(json_encode(OperationResponse::fromGatewayData($operation, $requestId)->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(0);
    $mock->assertSent(fn ($request): bool => $request instanceof $requestClass && $request->headers()->get('Idempotency-Key') === 'route-proof');
    expect($mock->getLastRequest())->toBeInstanceOf(ShowOperationRequest::class);
})->with([
    'create' => ['route:create', ['instance' => '12', 'domain' => 'private.test'], CreateRouteRequest::class, 'route.create'],
    'update' => ['route:update', ['route' => '7', '--domain' => 'private-next.test'], UpdateRouteRequest::class, 'route.update'],
    'destroy retry does not inspect the deleted Route' => ['route:destroy', ['route' => '7'], DestroyRouteRequest::class, 'route.destroy'],
    'target set' => ['route:target:set', ['route' => '7', 'target' => '12'], SetRouteTargetRequest::class, 'route.target_set'],
    'target unset retry skips confirmation and inspection' => ['route:target:unset', ['route' => '7'], UnsetRouteTargetRequest::class, 'route.target_unset'],
    'router unset' => ['cluster:router:unset', ['cluster' => '7', '--force' => true], UnsetClusterRouterRequest::class, 'cluster.router_unset'],
    'router set' => ['cluster:router:set', ['cluster' => '7', 'node' => '3'], SetClusterRouterRequest::class, 'cluster.router_set'],
]);

it('returns failure when an admitted Route Operation later refuses', function (): void {
    $operation = ['id' => 42, 'kind' => 'route.update', 'target_type' => 'route', 'target_id' => 7, 'input' => [], 'status' => 'queued', 'sequence' => 1];
    MockClient::global([
        UpdateRouteRequest::class => MockResponse::make(['data' => $operation, 'meta' => ['request_id' => 'route-proof']], 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => MockResponse::make(['data' => [...$operation, 'status' => 'failed', 'sequence' => 4, 'error_code' => 'route.lan_unreachable'], 'meta' => ['request_id' => 'route-proof']]),
    ]);
    $this->artisan('route:update', ['route' => '7', '--domain' => 'next.test', '--json' => true])->assertExitCode(1);
});
