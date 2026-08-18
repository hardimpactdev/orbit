<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\AppAnalyticsBinding;
use App\Models\AppWebSocketBinding;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Analytics\AnalyticsRouteRegistrar;
use App\Services\Proxy\PublicBindingProxyRouteOwnership;
use App\Services\WebSockets\WebSocketRouteRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    bind_dnsmasq_reconciler_test_double();
});

it('accepts a registrar-written public binding route', function (string $family): void {
    $route = write_public_binding_route($family);

    expect(app(PublicBindingProxyRouteOwnership::class)->matches($route))->toBeTrue();
})->with(['analytics', 'websocket']);

it('rejects a mutated ownership field on a registrar-written public binding route', function (
    string $family,
    string $field,
): void {
    $route = write_public_binding_route($family);
    $ownership = app(PublicBindingProxyRouteOwnership::class);

    expect($ownership->matches($route))
        ->toBeTrue()
        ->and($ownership->matches(mutate_public_binding_ownership_field($route, $field)))
        ->toBeFalse();
})->with(function (): array {
    $fields = [
        'domain',
        'node_id',
        'app_id',
        'workspace_id',
        'instance_id',
        'owner_type',
        'kind',
        'placement',
        'ingress_node_id',
        'protocol',
        'target',
        'upstream',
        'router_upstream',
        'router_backend_pool',
        'tls',
        'family_config',
        'router_artifact',
    ];
    $cases = [];

    foreach (['analytics', 'websocket'] as $family) {
        foreach ($fields as $field) {
            $cases["{$family} {$field}"] = [$family, $field];
        }
    }

    return $cases;
});

function write_public_binding_route(string $family): ProxyRoute
{
    $ingress = Node::factory()
        ->ingress()
        ->create([
            'name' => 'edge-1',
            'wireguard_address' => '10.6.0.10',
        ]);
    Node::factory()
        ->router()
        ->create([
            'name' => 'router-1',
            'wireguard_address' => '10.6.0.2',
        ]);
    Node::factory()
        ->withActiveRole($family)
        ->create([
            'name' => "{$family}-1",
            'wireguard_address' => '10.6.0.14',
        ]);
    $appNode = Node::factory()
        ->appProd()
        ->create([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.21',
        ]);
    $appNode
        ->roleAssignments()
        ->where('role', 'app-prod')
        ->update(['settings' => ['ingress_node_id' => $ingress->id]]);
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->for($app)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $appNode->id,
            domain: 'docs.test',
        ),
    ]);

    if ($family === 'analytics') {
        $binding = AppAnalyticsBinding::factory()->create([
            'instance_id' => $instance->id,
            'public_hosts' => ['analytics.docs.test'],
        ]);
        app(AnalyticsRouteRegistrar::class)->syncPublicHosts($binding);

        return ProxyRoute::query()->where('domain', 'analytics.docs.test')->firstOrFail();
    }

    $binding = AppWebSocketBinding::factory()->create([
        'instance_id' => $instance->id,
        'public_hosts' => ['ws.docs.test'],
    ]);
    app(WebSocketRouteRegistrar::class)->syncPublicHosts($binding);

    return ProxyRoute::query()->where('domain', 'ws.docs.test')->firstOrFail();
}

function mutate_public_binding_ownership_field(ProxyRoute $route, string $field): ProxyRoute
{
    $clone = $route->newInstance($route->getAttributes());
    $clone->exists = true;
    $config = is_array($clone->config) ? $clone->config : [];

    match ($field) {
        'domain' => $clone->forceFill(['domain' => 'mutated.example.test']),
        'node_id' => $clone->forceFill(['node_id' => Node::factory()->create()->id]),
        'app_id' => $clone->forceFill(['app_id' => App::factory()->create()->id]),
        'workspace_id' => $clone->forceFill([
            'workspace_id' => Workspace::factory()
                ->create([
                    'app_id' => $clone->app_id,
                    'instance_id' => $clone->instance_id,
                ])
                ->id,
        ]),
        'instance_id' => $clone->forceFill([
            'instance_id' => Instance::factory()->create([
                'app_id' => $clone->app_id,
                'name' => 'preview',
            ])->id,
        ]),
        'owner_type' => $clone->forceFill([
            'owner_type' => $clone->owner_type === 'app-analytics' ? 'app-websocket' : 'app-analytics',
        ]),
        'kind' => $clone->forceFill(['kind' => 'redirect']),
        'placement' => $clone->forceFill(['config' => [...$config, 'placement' => 'router']]),
        'ingress_node_id' => $clone->forceFill(['config' => [...$config, 'ingress_node_id' => 0]]),
        'protocol' => $clone->forceFill(['config' => [...$config, 'protocol' => 'http']]),
        'target' => $clone->forceFill([
            'config' => [...$config, 'target' => ['type' => 'http', 'value' => 'https://mutated.example.test']],
        ]),
        'upstream' => $clone->forceFill(['config' => [...$config, 'upstream' => 'https://mutated.example.test']]),
        'router_upstream' => $clone->forceFill([
            'config' => [
                ...$config,
                'router_upstream' => [
                    ...(is_array($config['router_upstream'] ?? null) ? $config['router_upstream'] : []),
                    'url' => 'http://10.9.9.9:80',
                ],
            ],
        ]),
        'router_backend_pool' => $clone->forceFill(['config' => [...$config, 'router_backend_pool' => []]]),
        'tls' => $clone->forceFill([
            'config' => [
                ...$config,
                'tls' => [
                    ...(is_array($config['tls'] ?? null) ? $config['tls'] : []),
                    'cert_path' => '/tmp/mutated.crt',
                ],
            ],
        ]),
        'family_config' => $clone->forceFill([
            'config' => $clone->owner_type === 'app-analytics'
                ? [...$config, 'tracking_paths' => ['/mutated']]
                : [
                    ...$config,
                    'router_backend_tls' => [
                        ...(is_array($config['router_backend_tls'] ?? null) ? $config['router_backend_tls'] : []),
                        'ca_path' => '/tmp/mutated.crt',
                    ],
                ],
        ]),
        'router_artifact' => $clone->forceFill([
            'config' => [
                ...$config,
                'router_artifact' => [
                    ...(is_array($config['router_artifact'] ?? null) ? $config['router_artifact'] : []),
                    'source_hash' => 'not-a-sha256',
                ],
            ],
        ]),
        default => throw new InvalidArgumentException("Unknown public binding mutation [{$field}]."),
    };

    return $clone;
}
