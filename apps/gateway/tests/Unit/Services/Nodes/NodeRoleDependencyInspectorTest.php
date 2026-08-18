<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\InstanceDriver;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Nodes\Roles\NodeRoleDependencyInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('removes only the node-bound instance and preserves an app instance on another node', function (): void {
    $prodNode = createTestAppHostNode(['name' => 'prod-node', 'tld' => 'prodtld'], 'app-prod');
    $devNode = createTestAppHostNode(['name' => 'dev-node', 'tld' => 'devtld']);

    // One logical app spanning two nodes: production on the prod node, development
    // on the dev node.
    $app = App::factory()->create(['name' => 'multi']);
    $productionInstance = Instance::factory()->for($app, 'app')->create([
        'name' => 'production',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $prodNode->id,
            node: $prodNode->name,
            path: '/srv/apps/multi',
            document_root: 'public',
            domain: 'multi.example.com',
        ),
    ]);
    $developmentInstance = Instance::factory()->for($app, 'app')->create([
        'name' => 'development',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $devNode->id,
            node: $devNode->name,
            path: '/home/orbit/apps/multi',
            document_root: 'public',
        ),
    ]);

    $assignment = new NodeRoleAssignment(['role' => 'app-prod']);
    $inspector = new NodeRoleDependencyInspector;

    // The production instance on the prod node is the single dependent.
    expect($inspector->dependentSummaries($prodNode, $assignment))
        ->toBe(['1 production app record']);

    $inspector->removeOrbitOwnedDependents($prodNode, $assignment);

    // Per-instance removal: the production instance on the prod node is gone,
    // but the logical app and its development instance on the other node survive.
    expect(Instance::query()->whereKey($productionInstance->id)->exists())
        ->toBeFalse()
        ->and(Instance::query()->whereKey($developmentInstance->id)->exists())
        ->toBeTrue()
        ->and(App::query()->whereKey($app->id)->exists())
        ->toBeTrue();
});

it('deletes the app only once its final instance is removed with the node role', function (): void {
    $devNode = createTestAppHostNode(['name' => 'dev-only-node', 'tld' => 'devonly']);

    $app = App::factory()->create(['name' => 'solo']);
    Instance::factory()->for($app, 'app')->create([
        'name' => 'development',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $devNode->id,
            node: $devNode->name,
            path: '/home/orbit/apps/solo',
            document_root: 'public',
        ),
    ]);

    $inspector = new NodeRoleDependencyInspector;
    $inspector->removeOrbitOwnedDependents($devNode, new NodeRoleAssignment(['role' => 'app-dev']));

    // The app had no other instance, so it is removed entirely.
    expect(App::query()->whereKey($app->id)->exists())
        ->toBeFalse()
        ->and(Instance::query()->where('app_id', $app->id)->exists())
        ->toBeFalse();
});

it('removes only valid instance-owned routes with an app role dependent', function (): void {
    $devNode = createTestAppHostNode(['name' => 'dev-node', 'tld' => 'dev']);
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->for($app)->create([
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $devNode->id),
    ]);
    $validRoute = ProxyRoute::factory()->create([
        'node_id' => $devNode->id,
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'domain' => 'docs.dev',
        'owner_type' => 'app',
        'kind' => 'app',
    ]);
    $malformedRoute = ProxyRoute::factory()->create([
        'node_id' => $devNode->id,
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'domain' => 'malformed.dev',
        'owner_type' => 'app',
        'kind' => 'app',
    ]);
    $malformedRoute->forceFill([
        'app_id' => App::factory()->create(['name' => 'compatibility'])->id,
    ])->saveQuietly();
    $strayForeignKeyRoute = persist_made_proxy_route_bypassing_owner_guard(ProxyRoute::factory()->make([
        'node_id' => $devNode->id,
        'app_id' => null,
        'instance_id' => $instance->id,
        'domain' => 'custom.dev',
        'owner_type' => 'custom',
        'kind' => 'proxy',
    ]));

    new NodeRoleDependencyInspector()->removeOrbitOwnedDependents(
        $devNode,
        new NodeRoleAssignment(['role' => 'app-dev']),
    );

    expect(ProxyRoute::query()->whereKey($validRoute->id)->exists())
        ->toBeFalse()
        ->and(ProxyRoute::query()->whereKey($malformedRoute->id)->exists())
        ->toBeTrue()
        ->and(ProxyRoute::query()->whereKey($strayForeignKeyRoute->id)->exists())
        ->toBeTrue();
});

it('classifies ingress routes by their concrete instance instead of any app sibling', function (): void {
    $ingress = createTestAppHostNode(['name' => 'ingress-node', 'tld' => 'edge'], 'ingress');
    $productionNode = createTestAppHostNode(['name' => 'production-node', 'tld' => 'prod'], 'app-prod');
    $developmentNode = createTestAppHostNode(['name' => 'development-node', 'tld' => 'dev']);
    $app = App::factory()->create(['name' => 'multi']);
    $compatibilityApp = App::factory()->create(['name' => 'compatibility']);
    $production = Instance::factory()->for($app)->create([
        'name' => 'production',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $productionNode->id,
            domain: 'multi.example.com',
        ),
    ]);
    $development = Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $developmentNode->id),
    ]);
    $developmentRoute = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'app_id' => $app->id,
        'instance_id' => $development->id,
        'domain' => 'development.example.com',
        'owner_type' => 'app',
        'kind' => 'app',
        'config' => ['placement' => 'ingress'],
    ]);
    $validProductionRoute = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'app_id' => $app->id,
        'instance_id' => $production->id,
        'domain' => 'valid-production.example.com',
        'owner_type' => 'app',
        'kind' => 'app',
        'config' => ['placement' => 'ingress'],
    ]);
    $productionRoute = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'app_id' => $app->id,
        'instance_id' => $production->id,
        'domain' => 'production.example.com',
        'owner_type' => 'app',
        'kind' => 'app',
        'config' => ['placement' => 'ingress'],
    ]);
    $productionRoute->forceFill(['app_id' => $compatibilityApp->id])->saveQuietly();

    new NodeRoleDependencyInspector()->removeOrbitOwnedDependents(
        $ingress,
        new NodeRoleAssignment(['role' => 'ingress']),
    );

    expect(ProxyRoute::query()->whereKey($developmentRoute->id)->exists())
        ->toBeTrue()
        ->and(ProxyRoute::query()->whereKey($validProductionRoute->id)->exists())
        ->toBeFalse()
        ->and(ProxyRoute::query()->whereKey($productionRoute->id)->exists())
        ->toBeTrue();
});

it('does not classify or remove a workspace ingress route with conflicting app ownership', function (): void {
    $ingress = createTestAppHostNode(['name' => 'ingress-node', 'tld' => 'edge'], 'ingress');
    $productionNode = createTestAppHostNode(['name' => 'production-node', 'tld' => 'prod'], 'app-prod');
    $app = App::factory()->create(['name' => 'docs']);
    $otherApp = App::factory()->create(['name' => 'other']);
    $production = Instance::factory()->for($app)->create([
        'name' => 'production',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $productionNode->id,
            domain: 'docs.example.com',
        ),
    ]);
    $workspace = Workspace::factory()->for($app)->create([
        'name' => 'feature',
        'instance_id' => $production->id,
    ]);
    $route = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'app_id' => $app->id,
        'instance_id' => $production->id,
        'workspace_id' => $workspace->id,
        'domain' => 'feature.docs.example.com',
        'owner_type' => 'workspace',
        'kind' => 'workspace',
        'config' => ['placement' => 'ingress'],
    ]);
    $workspace->forceFill(['app_id' => $otherApp->id])->save();
    $assignment = new NodeRoleAssignment(['role' => 'ingress']);
    $inspector = new NodeRoleDependencyInspector;

    expect($inspector->dependentSummaries($ingress, $assignment))->toBe([]);

    $inspector->removeOrbitOwnedDependents($ingress, $assignment);

    expect(ProxyRoute::query()->whereKey($route->id)->exists())->toBeTrue();
});

it('does not summarize or remove an ingress app route with invalid ownership', function (string $invalidity): void {
    $ingress = createTestAppHostNode(['name' => 'ingress-node', 'tld' => 'edge'], 'ingress');
    $productionNode = createTestAppHostNode(['name' => 'production-node', 'tld' => 'prod'], 'app-prod');
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->for($app)->create([
        'name' => 'production',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $productionNode->id,
            domain: 'docs.example.com',
        ),
    ]);
    $route = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'domain' => 'docs.example.com',
        'owner_type' => 'app',
        'kind' => 'app',
        'config' => ['placement' => 'ingress'],
    ]);

    if ($invalidity === 'missing app') {
        $route->forceFill(['app_id' => null])->saveQuietly();
    }

    if ($invalidity === 'missing instance') {
        $route->forceFill(['instance_id' => null])->saveQuietly();
    }

    if ($invalidity === 'conflicting app') {
        $route->forceFill(['app_id' => App::factory()->create()->id])->saveQuietly();
    }

    if ($invalidity === 'wrong kind') {
        $route->forceFill(['kind' => 'proxy'])->saveQuietly();
    }

    if ($invalidity === 'workspace identity') {
        $workspace = Workspace::factory()->for($app)->create(['instance_id' => $instance->id]);
        $route->forceFill(['workspace_id' => $workspace->id])->saveQuietly();
    }

    $assignment = new NodeRoleAssignment(['role' => 'ingress']);
    $inspector = new NodeRoleDependencyInspector;

    expect($inspector->dependentSummaries($ingress, $assignment))->toBe([]);

    $inspector->removeOrbitOwnedDependents($ingress, $assignment);

    expect(ProxyRoute::query()->whereKey($route->id)->exists())->toBeTrue();
})->with([
    'missing app',
    'missing instance',
    'conflicting app',
    'wrong kind',
    'workspace identity',
]);

it('reports and removes valid analytics websocket and s3 ingress dependents', function (): void {
    $ingress = Node::factory()->ingress()->create(['name' => 'edge-1']);
    $router = Node::factory()
        ->router()
        ->create([
            'name' => 'router-1',
            'wireguard_address' => '10.6.0.2',
        ]);
    $appNode = Node::factory()->create(['name' => 'app-1']);
    NodeRoleAssignment::factory()->create([
        'node_id' => $appNode->id,
        'role' => 'app-prod',
        'status' => 'active',
        'settings' => ['ingress_node_id' => $ingress->id],
    ]);
    $analyticsBackend = Node::factory()
        ->withActiveRole('analytics')
        ->create([
            'name' => 'analytics-1',
            'wireguard_address' => '10.6.0.50',
        ]);
    $websocketBackend = Node::factory()
        ->withActiveRole('websocket')
        ->create([
            'name' => 'websocket-1',
            'wireguard_address' => '10.6.0.51',
        ]);
    $storage = Node::factory()->withActiveRole('s3')->create(['name' => 'storage-1']);
    NodeTool::factory()->for($storage)->create([
        'name' => 'seaweedfs',
        'expected_state' => 'installed',
        'config' => ['backend_host' => 'storage-1.s3.orbit', 'public_hosts' => ['s3.docs.test']],
    ]);
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->for($app)->create([
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $appNode->id),
    ]);

    $analyticsRoute = proxyRolePublicBindingRoute(
        $ingress,
        $router,
        $analyticsBackend,
        $app,
        $instance,
        'analytics',
    );
    $websocketRoute = proxyRolePublicBindingRoute(
        $ingress,
        $router,
        $websocketBackend,
        $app,
        $instance,
        'websocket',
    );
    $s3Route = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'domain' => 's3.docs.test',
        'owner_type' => 's3',
        'kind' => 'proxy',
        'config' => [
            'placement' => 'ingress',
            'owner_name' => 'seaweedfs',
            'protocol' => 's3',
            'target' => ['type' => 'upstream', 'value' => 'https://s3.orbit'],
            'router_upstream' => [
                'node_id' => $router->id,
                'node' => $router->name,
                'url' => 'http://10.6.0.2:80',
            ],
            'tls' => [
                'cert_path' => '/etc/orbit/certs/s3.docs.test.crt',
                'key_path' => '/etc/orbit/certs/s3.docs.test.key',
            ],
        ],
    ]);
    $malformedAnalyticsRoute = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'domain' => 'malformed-analytics.docs.test',
        'owner_type' => 'app-analytics',
        'kind' => 'proxy',
        'config' => [
            ...$analyticsRoute->config,
            'protocol' => 'websocket',
        ],
    ]);
    $malformedS3Route = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'domain' => 'malformed-s3.docs.test',
        'owner_type' => 's3',
        'kind' => 'proxy',
        'config' => [
            ...$s3Route->config,
            'target' => ['type' => 'upstream', 'value' => 'https://unrelated.orbit'],
        ],
    ]);
    $customRoute = ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'domain' => 'custom.docs.test',
        'owner_type' => 'custom',
        'kind' => 'proxy',
        'config' => [
            'placement' => 'ingress',
            'target' => ['type' => 'upstream', 'value' => 'https://custom.test'],
        ],
    ]);
    $assignment = new NodeRoleAssignment(['role' => 'ingress']);
    $inspector = new NodeRoleDependencyInspector;

    expect($inspector->dependentSummaries($ingress, $assignment))
        ->toBe(['3 public proxy route records']);

    $inspector->removeOrbitOwnedDependents($ingress, $assignment);

    expect(ProxyRoute::query()->whereKey($analyticsRoute->id)->exists())
        ->toBeFalse()
        ->and(ProxyRoute::query()->whereKey($websocketRoute->id)->exists())
        ->toBeFalse()
        ->and(ProxyRoute::query()->whereKey($s3Route->id)->exists())
        ->toBeFalse()
        ->and(ProxyRoute::query()->whereKey($malformedAnalyticsRoute->id)->exists())
        ->toBeTrue()
        ->and(ProxyRoute::query()->whereKey($malformedS3Route->id)->exists())
        ->toBeTrue()
        ->and(ProxyRoute::query()->whereKey($customRoute->id)->exists())
        ->toBeTrue();
});

/**
 * @mago-expect lint:excessive-parameter-list
 */
function proxyRolePublicBindingRoute(
    Node $ingress,
    Node $router,
    Node $backend,
    App $app,
    Instance $instance,
    string $protocol,
): ProxyRoute {
    $domain = "{$protocol}.docs.test";
    $config = [
        'placement' => 'ingress',
        'ingress_node_id' => $ingress->id,
        'protocol' => $protocol,
        'target' => ['type' => $protocol, 'value' => "https://{$protocol}.orbit"],
        'upstream' => "https://{$protocol}.orbit",
        'router_upstream' => [
            'node_id' => $router->id,
            'node' => $router->name,
            'url' => 'http://10.6.0.2:80',
        ],
        'router_backend_pool' => [[
            'node_id' => $backend->id,
            'node' => $backend->name,
            'url' => $protocol === 'analytics' ? 'http://10.6.0.50:8000' : 'https://10.6.0.51:8080',
        ]],
        'router_artifact' => [
            'node_id' => $router->id,
            'node' => $router->name,
            'source_hash' => str_repeat('a', 64),
        ],
        'tls' => [
            'cert_path' => "/etc/orbit/certs/{$domain}.crt",
            'key_path' => "/etc/orbit/certs/{$domain}.key",
        ],
    ];

    if ($protocol === 'analytics') {
        $config['tracking_paths'] = ['/js/*', '/api/event'];
    } else {
        $config['router_backend_tls'] = [
            'trusted_by_gateway_ca' => true,
            'ca_path' => '/etc/orbit/ca/root.crt',
        ];
    }

    return ProxyRoute::factory()->create([
        'node_id' => $ingress->id,
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'domain' => $domain,
        'owner_type' => "app-{$protocol}",
        'kind' => 'proxy',
        'config' => $config,
    ]);
}
