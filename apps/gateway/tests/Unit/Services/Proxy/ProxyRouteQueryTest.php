<?php

declare(strict_types=1);

use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Proxy\ProxyRouteQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $aNode->id]);

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

        expect(array_column($result['routes'], 'domain'))->toBe(['docs.test', 'z.test'])
            ->and($result['meta'])->toBe([
                'filter' => 'all',
                'node' => null,
                'count' => 2,
            ])
            ->and($result['routes'][0])->toMatchArray([
                'domain' => 'docs.test',
                'kind' => 'app',
                'owner' => ['type' => 'app', 'name' => 'docs'],
                'node' => 'a-node',
                'target' => ['type' => 'app', 'value' => 'docs'],
                'redirect_code' => null,
                'tls' => ['managed_by' => 'orbit', 'trusted_by_gateway_ca' => true],
                'status' => 'expected',
            ])
            ->and($result['routes'][1])->toMatchArray([
                'domain' => 'z.test',
                'kind' => 'redirect',
                'owner' => ['type' => 'custom', 'name' => null],
                'target' => ['type' => 'redirect', 'value' => 'https://docs.test'],
                'redirect_code' => 302,
            ]);
    });

    it('includes ingress placement and router backend pool metadata for production routes', function (): void {
        $edge = Node::factory()->create(['name' => 'edge-1']);
        $router = Node::factory()->create(['name' => 'gateway-1']);
        $backend = Node::factory()->create(['name' => 'web-1']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $backend->id, 'environment' => 'production']);

        ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
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

        expect($route)->toMatchArray([
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
            ->and($route['router']['backend_pool'][0])->not->toHaveKey('node_id');
    });

    it('applies route filters after visibility is resolved', function (): void {
        $node = Node::factory()->create(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $workspace = Workspace::factory()->create(['name' => 'feature', 'app_id' => $app->id]);

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
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

        expect(array_column($query->list(filter: 'app')['routes'], 'domain'))->toBe(['docs.test'])
            ->and(array_column($query->list(filter: 'workspace')['routes'], 'domain'))->toBe(['feature.docs.test'])
            ->and(array_column($query->list(filter: 'custom')['routes'], 'domain'))->toBe(['custom.test'])
            ->and(array_column($query->list(filter: 'redirect')['routes'], 'domain'))->toBe(['old.test']);
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

        expect(array_column($result['routes'], 'domain'))->toBe(['visible.test'])
            ->and($result['meta']['node'])->toBe('visible-node');

        $query->list(node: 'hidden-node', caller: $caller);
    })->throws(GatewayApiException::class, "Unknown node: 'hidden-node'.");

    it('fails authorization when app callers have no route visibility', function (): void {
        $caller = Node::factory()->appDev()->create();

        app(ProxyRouteQuery::class)->list(caller: $caller);
    })->throws(GatewayApiException::class, 'This node is not authorized to read the proxy route registry.');
});
