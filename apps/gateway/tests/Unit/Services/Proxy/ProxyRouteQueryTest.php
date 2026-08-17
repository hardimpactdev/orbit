<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\InstanceDriver;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Proxy\ProxyRouteQuery;
use App\Services\Proxy\WorkspaceProxyRouteOwnershipResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orbit\Sdk\Laravel\GatewayApiException;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function grantProxyRouteQueryAccess(Node $caller, Node $servingNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $servingNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('ProxyRouteQuery', function (): void {
    it('normalizes proxy route entities and sorts them by node then domain', function (): void {
        $zNode = Node::factory()->create(['name' => 'z-node']);
        $aNode = Node::factory()->create(['name' => 'a-node']);
        $app = App::factory()->create(['name' => 'docs']);
        $instance = Instance::factory()->for($app)->create(['name' => 'primary']);

        ProxyRoute::factory()->create([
            'node_id' => $zNode->id,
            'domain' => 'z.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
            'config' => [
                'target' => ['value' => 'https://docs.test'],
                'code' => 302,
            ],
        ]);

        ProxyRoute::factory()->create([
            'node_id' => $aNode->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'tls' => [
                    'managed_by' => 'orbit',
                    'trusted_by_gateway_ca' => true,
                ],
            ],
        ]);

        $result = app(ProxyRouteQuery::class)->list();

        expect(array_column($result['routes'], 'domain'))
            ->toBe(['docs.test', 'z.test'])
            ->and($result['meta'])
            ->toBe([
                'filter' => 'all',
                'node' => null,
                'count' => 2,
            ])
            ->and($result['routes'][0])
            ->toMatchArray([
                'domain' => 'docs.test',
                'kind' => 'instance',
                'owner' => ['type' => 'instance', 'name' => 'docs.primary'],
                'node' => 'a-node',
                'target' => ['type' => 'instance', 'value' => 'docs.primary'],
                'redirect_code' => null,
                'tls' => ['managed_by' => 'orbit', 'trusted_by_gateway_ca' => true],
                'status' => 'unknown',
            ])
            ->and($result['routes'][1])
            ->toMatchArray([
                'domain' => 'z.test',
                'kind' => 'redirect',
                'owner' => ['type' => 'custom', 'name' => null],
                'target' => ['type' => 'redirect', 'value' => 'https://docs.test'],
                'redirect_code' => 302,
            ]);
    });

    it('reports persisted partial enactment instead of treating registry intent as expected runtime state', function (): void {
        $node = Node::factory()->create(['name' => 'gateway-1']);

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'partial.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => [
                'upstream' => 'http://10.6.0.21:8080',
                'enactment' => [
                    'status' => 'partial',
                    'completed_operations' => [
                        [
                            'layer' => 'backend',
                            'node' => 'main1',
                            'operation' => 'caddy.global.ensure',
                        ],
                    ],
                    'failure' => [
                        'layer' => 'router',
                        'node' => 'gateway-1',
                        'operation' => 'caddy.router.install',
                    ],
                ],
            ],
        ]);

        expect(app(ProxyRouteQuery::class)->list()['routes'][0]['status'])
            ->toBe('partial');
    });

    it('reports canonical app instance primary route owner and target selectors', function (): void {
        $node = Node::factory()
            ->appDev(['tld' => 'nmbp'])
            ->create([
                'name' => 'nmbp',
                'tld' => 'nmbp',
            ]);
        $app = App::factory()->create(['name' => 'happie']);
        Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver' => InstanceDriver::Orbit,
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $node->id,
                node: 'nmbp',
                path: '/Users/nckrtl/apps/happie-development',
                document_root: 'public',
                domain: 'happie.nmbp',
            ),
        ]);
        $instance = Instance::factory()->for($app)->create([
            'name' => 'nmbp',
            'driver' => InstanceDriver::Orbit,
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $node->id,
                node: 'nmbp',
                path: '/Users/nckrtl/apps/happie',
                document_root: 'public',
                domain: 'happie.nmbp',
            ),
        ]);

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'domain' => 'happie.nmbp',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'document_root' => '/Users/nckrtl/apps/happie/public',
                'runtime_upstream' => 'https://orbit-app-happie:8443',
                'runtime_upstream_tls' => [
                    'trusted_by_gateway_ca' => true,
                    'ca_path' => '/etc/orbit/ca/root.crt',
                    'server_name' => 'happie.test',
                ],
            ],
        ]);

        $route = app(ProxyRouteQuery::class)->list(filter: 'instance')['routes'][0];

        expect($route)
            ->toMatchArray([
                'domain' => 'happie.nmbp',
                'kind' => 'instance',
                'owner' => ['type' => 'instance', 'name' => 'happie.nmbp'],
                'target' => ['type' => 'instance', 'value' => 'happie.nmbp'],
            ]);
    });

    it('does not project invalid app routes as instance-owned registry entities', function (string $invalidity): void {
        $node = Node::factory()->create(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs']);
        $instance = Instance::factory()->for($app)->create(['name' => 'development']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);

        if ($invalidity === 'missing instance') {
            $route->forceFill(['instance_id' => null])->save();
        }

        if ($invalidity === 'conflicting app') {
            $route->forceFill(['app_id' => App::factory()->create()->id])->save();
        }

        if ($invalidity === 'malformed kind') {
            $route->forceFill(['kind' => 'proxy'])->save();
        }

        $query = app(ProxyRouteQuery::class);
        $entity = $query->toRouteEntity($route->fresh());

        expect($entity['kind'])
            ->not
            ->toBe('instance')
            ->and($entity['owner'])
            ->toBe(['type' => 'app', 'name' => null])
            ->and($entity['target'])
            ->toBe(['type' => 'upstream', 'value' => null])
            ->and($query->list(filter: 'instance')['routes'])
            ->toBeEmpty();
    })->with([
        'missing instance',
        'conflicting app',
        'malformed kind',
    ]);

    it('fails closed when workspace ownership cannot be resolved', function (string $invalidity): void {
        $node = Node::factory()->create(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs']);
        $workspace = Workspace::factory()->for($app)->create(['name' => 'feature']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'instance_id' => $workspace->instance_id,
            'workspace_id' => $workspace->id,
            'domain' => 'feature.docs.test',
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);

        if ($invalidity === 'missing workspace') {
            $route->forceFill(['workspace_id' => null])->save();
        }

        if ($invalidity === 'conflicting app') {
            $route->forceFill(['app_id' => App::factory()->create(['name' => 'other'])->id])->save();
        }

        if ($invalidity === 'malformed kind') {
            $route->forceFill(['kind' => 'proxy'])->save();
        }

        $entity = app(ProxyRouteQuery::class)->toRouteEntity($route->fresh());

        expect($entity)
            ->not
            ->toHaveKey('instance')
            ->and($entity['owner'])
            ->toBe(['type' => 'workspace', 'name' => null])
            ->and($entity['target'])
            ->toBe(['type' => 'workspace', 'value' => null]);
    })->with([
        'missing workspace',
        'conflicting app',
        'malformed kind',
    ]);

    it('rejects workspace owner and kind tuple mismatches in the centralized resolver', function (
        string $ownerType,
        string $kind,
    ): void {
        $node = Node::factory()->create(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs']);
        $workspace = Workspace::factory()->for($app)->create(['name' => 'feature']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'instance_id' => $workspace->instance_id,
            'workspace_id' => $workspace->id,
            'domain' => 'feature.docs.test',
            'owner_type' => $ownerType,
            'kind' => $kind,
        ]);

        expect(app(WorkspaceProxyRouteOwnershipResolver::class)->resolve($route))->toBeNull();
    })->with([
        'owner mismatch' => ['app', 'workspace'],
        'kind mismatch' => ['workspace', 'proxy'],
    ]);

    it('includes ingress placement and router backend pool metadata for production routes', function (): void {
        $edge = Node::factory()->create(['name' => 'edge-1']);
        $router = Node::factory()->create(['name' => 'gateway-1']);
        $backend = Node::factory()->create(['name' => 'web-1']);
        $app = App::factory()->create(['name' => 'docs']);
        $instance = Instance::factory()->for($app)->create();

        ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'router_upstream' => [
                    'node_id' => $router->id,
                    'node' => 'gateway-1',
                    'url' => 'http://10.6.0.2:80',
                ],
                'router_backend_pool' => [
                    ['node_id' => $backend->id, 'node' => 'web-1', 'url' => 'http://10.6.0.21:80'],
                ],
                'backend_artifacts' => [
                    ['node_id' => $backend->id, 'bind' => '10.6.0.21'],
                ],
            ],
        ]);

        $route = app(ProxyRouteQuery::class)->list()['routes'][0];

        expect($route)
            ->toMatchArray([
                'domain' => 'docs.test',
                'node' => 'edge-1',
                'placement' => 'ingress',
                'router' => [
                    'node' => 'gateway-1',
                    'url' => 'http://10.6.0.2:80',
                    'backend_pool' => [
                        ['node' => 'web-1', 'url' => 'http://10.6.0.21:80'],
                    ],
                ],
            ])
            ->and($route['router']['backend_pool'][0])
            ->not->toHaveKey('node_id');
    });

    it('normalizes websocket service routes and selects them via the websocket service filter', function (): void {
        $router = Node::factory()->router()->create(['name' => 'router-1']);

        ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 'websocket.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => [
                'protocol' => 'websocket',
                'router_backend_pool' => [
                    ['node_id' => 42, 'node' => 'app-dev-1', 'url' => 'https://10.6.0.44:8080'],
                ],
                'tls' => [
                    'managed_by' => 'internal',
                    'trusted_by_gateway_ca' => true,
                ],
            ],
        ]);

        $result = app(ProxyRouteQuery::class)->list(filter: 'websocket');

        expect($result['meta'])
            ->toBe([
                'filter' => 'websocket',
                'node' => null,
                'count' => 1,
            ])
            ->and($result['routes'][0])
            ->toMatchArray([
                'domain' => 'websocket.orbit',
                'kind' => 'proxy',
                'owner' => ['type' => 'router', 'name' => 'websocket.orbit'],
                'node' => 'router-1',
                'target' => ['type' => 'upstream', 'value' => 'websocket.orbit'],
                'tls' => ['managed_by' => 'internal', 'trusted_by_gateway_ca' => true],
            ]);
    });

    it('websocket service filter does not include non-service-domain router routes', function (): void {
        $router = Node::factory()->router()->create(['name' => 'router-1']);

        // A router-owned route at a different domain — must NOT appear under websocket filter
        ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 'other.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => ['protocol' => 'other'],
        ]);

        // The websocket.orbit service route — must appear
        ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 'websocket.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => ['protocol' => 'websocket'],
        ]);

        $result = app(ProxyRouteQuery::class)->list(filter: 'websocket');

        expect($result['meta']['count'])->toBe(1)->and($result['routes'][0]['domain'])->toBe('websocket.orbit');
    });

    it('normalizes app websocket public routes and filters them by owner', function (): void {
        $edge = Node::factory()->ingress()->create(['name' => 'edge-1']);
        $router = Node::factory()->router()->create(['name' => 'router-1']);
        $appNode = Node::factory()->appProd()->create(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs']);
        $instance = Instance::factory()->for($app)->create();

        ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'domain' => 'ws.docs.test',
            'owner_type' => 'app-websocket',
            'kind' => 'proxy',
            'config' => [
                'placement' => 'ingress',
                'target' => [
                    'type' => 'websocket',
                    'value' => 'https://websocket.orbit',
                ],
                'router_upstream' => [
                    'node_id' => $router->id,
                    'node' => 'router-1',
                    'url' => 'http://10.6.0.2:80',
                ],
                'router_backend_pool' => [
                    ['node_id' => $router->id, 'node' => 'router-1', 'url' => 'https://websocket.orbit'],
                ],
            ],
        ]);

        $result = app(ProxyRouteQuery::class)->list(filter: 'websocket');

        expect($result['meta'])
            ->toBe([
                'filter' => 'websocket',
                'node' => null,
                'count' => 1,
            ])
            ->and($result['routes'][0])
            ->toMatchArray([
                'domain' => 'ws.docs.test',
                'kind' => 'proxy',
                'owner' => ['type' => 'websocket', 'name' => 'docs'],
                'node' => 'edge-1',
                'target' => ['type' => 'websocket', 'value' => 'https://websocket.orbit'],
                'placement' => 'ingress',
                'router' => [
                    'node' => 'router-1',
                    'url' => 'http://10.6.0.2:80',
                    'backend_pool' => [
                        ['node' => 'router-1', 'url' => 'https://websocket.orbit'],
                    ],
                ],
            ]);
    });

    it('enriches workspace route entities with canonical parent app.instance from the FK workspace only', function (): void {
        $node = Node::factory()->appDev()->create(['name' => 'app-dev-1']);
        $app = App::factory()->create(['name' => 'docs']);
        $development = Instance::factory()->create([
            'app_id' => $app->id,
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $node->id,
                node: $node->name,
                path: '/srv/apps/docs',
            ),
        ]);
        $staging = Instance::factory()->create([
            'app_id' => $app->id,
            'name' => 'staging',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $node->id,
                node: $node->name,
                path: '/srv/apps/docs-staging',
            ),
        ]);
        $workspaceDevelopment = Workspace::factory()->create([
            'name' => 'feature-dev',
            'app_id' => $app->id,
            'instance_id' => $development->id,
        ]);
        $workspaceStaging = Workspace::factory()->create([
            'name' => 'feature-staging',
            'app_id' => $app->id,
            'instance_id' => $staging->id,
        ]);

        $routeDevelopment = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'workspace_id' => $workspaceDevelopment->id,
            'instance_id' => $development->id,
            'domain' => 'feature.docs.development.test',
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);
        $routeStaging = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'workspace_id' => $workspaceStaging->id,
            'instance_id' => $staging->id,
            'domain' => 'feature.docs.staging.test',
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);

        $query = app(ProxyRouteQuery::class);

        expect($query->toRouteEntity($routeDevelopment->fresh())['instance'])
            ->toBe('docs.development')
            ->and($query->toRouteEntity($routeStaging->fresh())['instance'])
            ->toBe('docs.staging')
            ->and($query->toRouteEntity($routeDevelopment->fresh())['owner'])
            ->toMatchArray(['type' => 'workspace', 'name' => 'feature-dev']);
    });

    it('applies route filters after visibility is resolved', function (): void {
        $node = Node::factory()->appDev()->create(['name' => 'app-1']);
        $app = App::factory()->placedOn($node)->create(['name' => 'docs']);
        $instance = $app->instances()->sole();
        $workspace = Workspace::factory()->create([
            'name' => 'feature',
            'app_id' => $app->id,
            'instance_id' => $instance->id,
        ]);

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'workspace_id' => $workspace->id,
            'domain' => 'feature.docs.test',
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'custom.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['upstream' => 'http://127.0.0.1:9000'],
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'old.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
        ]);

        $query = app(ProxyRouteQuery::class);

        expect(fn (): array => $query->list(filter: 'app'))
            ->toThrow(GatewayApiException::class)
            ->and(array_column($query->list(filter: 'instance')['routes'], 'domain'))
            ->toBe(['docs.test'])
            ->and(array_column($query->list(filter: 'workspace')['routes'], 'domain'))
            ->toBe(['feature.docs.test'])
            ->and(array_column($query->list(filter: 'custom')['routes'], 'domain'))
            ->toBe(['custom.test'])
            ->and(array_column($query->list(filter: 'redirect')['routes'], 'domain'))
            ->toBe(['old.test']);
    });

    it('s3 service filter selects router-owned s3.orbit route and public s3 host routes', function (): void {
        $router = Node::factory()->router()->create(['name' => 'router-1']);
        $edge = Node::factory()->ingress()->create(['name' => 'edge-1']);
        $app = App::factory()->create();
        $instance = Instance::factory()->for($app)->create();

        // The router-owned s3.orbit service route
        ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 's3.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => ['protocol' => 's3'],
        ]);

        // A public S3 host route (owner s3)
        ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'domain' => 's3.example.com',
            'owner_type' => 's3',
            'kind' => 'proxy',
            'config' => ['placement' => 'ingress', 'protocol' => 's3'],
        ]);

        // A route with a different owner — must NOT appear
        ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'domain' => 'app.example.com',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);

        $result = app(ProxyRouteQuery::class)->list(filter: 's3');

        $domains = array_column($result['routes'], 'domain');
        sort($domains);

        expect($domains)->toBe(['s3.example.com', 's3.orbit'])->and($result['meta']['count'])->toBe(2);
    });

    it('filters by visible serving node and rejects unknown node scope', function (): void {
        $caller = Node::factory()->appDev()->create();
        $visibleNode = Node::factory()->create(['name' => 'visible-node']);
        $hiddenNode = Node::factory()->create(['name' => 'hidden-node']);
        grantProxyRouteQueryAccess($caller, $visibleNode);

        ProxyRoute::factory()->create(['node_id' => $visibleNode->id, 'domain' => 'visible.test']);
        ProxyRoute::factory()->create(['node_id' => $hiddenNode->id, 'domain' => 'hidden.test']);

        $query = app(ProxyRouteQuery::class);
        $result = $query->list(node: 'visible-node', caller: $caller);

        expect(array_column($result['routes'], 'domain'))
            ->toBe(['visible.test'])
            ->and($result['meta']['node'])
            ->toBe('visible-node');

        $query->list(node: 'hidden-node', caller: $caller);
    })->throws(GatewayApiException::class, "Unknown node: 'hidden-node'.");

    it('fails authorization when app callers have no route visibility', function (): void {
        $caller = Node::factory()->appDev()->create();

        app(ProxyRouteQuery::class)->list(caller: $caller);
    })->throws(GatewayApiException::class, 'This node is not authorized to read the proxy route registry.');
});
