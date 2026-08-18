<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\InstanceDriver;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const PROXY_ROUTE_LIST_CALLER_WG_IP = '10.6.0.91';

function createProxyRouteListCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => PROXY_ROUTE_LIST_CALLER_WG_IP,
        'wireguard_address' => PROXY_ROUTE_LIST_CALLER_WG_IP,
    ], $overrides));
}

function grantProxyRouteListAccess(Node $caller, Node $servingNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $servingNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function assignProxyRouteListRole(Node $node, string $role = 'gateway'): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => $role === 'app-dev' ? ['tld' => 'test'] : [],
    ]);
}

function proxy_route_list_workspace_on_node(Node $node): Workspace
{
    $app = App::factory()->create([
        'name' => 'docs',
    ]);
    $instance = $app->instances()->create([
        'name' => 'development',
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/srv/apps/docs',
            document_root: 'public',
            domain: 'docs.test',
        ),
    ]);

    return Workspace::factory()
        ->for($app)
        ->for($instance, 'instance')
        ->create([
            'name' => 'feature-docs',
            'path' => '/srv/apps/docs/workspaces/feature-docs',
        ]);
}

function proxy_route_list_workspace_boundary(string $boundary): Node
{
    $caller = createProxyRouteListCallerNode();

    if ($boundary === 'consumer') {
        assignProxyRouteListRole($caller, role: 'app-prod');
        $workspaceNode = Node::factory()->appDev()->create(['name' => 'workspace-dev']);
        NodeAccess::query()->create([
            'consumer_node_id' => $caller->id,
            'serving_node_id' => $workspaceNode->id,
            'permissions' => ['proxy:*'],
        ]);

        return $workspaceNode;
    }

    assignProxyRouteListRole($caller);

    return Node::factory()->appProd()->create(['name' => 'workspace-prod']);
}

describe('ProxyRouteListController', function (): void {
    it('lists visible proxy routes with filter metadata', function (): void {
        $caller = createProxyRouteListCallerNode();
        $visibleNode = Node::factory()->create(['name' => 'app-1']);
        $hiddenNode = Node::factory()->create(['name' => 'app-2']);
        grantProxyRouteListAccess($caller, $visibleNode);
        $app = App::factory()->create(['name' => 'docs']);
        $instance = Instance::factory()->for($app)->create(['name' => 'development']);

        ProxyRoute::factory()->create([
            'node_id' => $visibleNode->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $hiddenNode->id,
            'domain' => 'hidden.test',
        ]);

        $response = $this->call(
            'GET',
            '/api/proxy-routes?filter=instance',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROXY_ROUTE_LIST_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'success.data.routes')
            ->assertJsonPath('success.data.routes.0.domain', 'docs.test')
            ->assertJsonPath('success.data.routes.0.kind', 'instance')
            ->assertJsonPath('success.data.routes.0.owner.type', 'instance')
            ->assertJsonPath('success.data.routes.0.owner.name', 'docs.development')
            ->assertJsonPath('success.data.routes.0.target.type', 'instance')
            ->assertJsonPath('success.data.routes.0.target.value', 'docs.development')
            ->assertJsonPath('success.meta.filter', 'instance')
            ->assertJsonPath('success.meta.node', null)
            ->assertJsonPath('success.meta.count', 1);
    });

    it('lets gateway callers read all route intent', function (): void {
        $caller = createProxyRouteListCallerNode();
        assignProxyRouteListRole($caller);

        ProxyRoute::factory()->count(2)->create();

        $response = $this->call(
            'GET',
            '/api/proxy-routes',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROXY_ROUTE_LIST_CALLER_WG_IP],
        );

        $response->assertOk()
            ->assertJsonCount(2, 'success.data.routes');
    });

    it('redacts forbidden workspace routes from broad reads and rejects explicit workspace filters without effects', function (
        string $boundary,
    ): void {
        $workspaceNode = proxy_route_list_workspace_boundary($boundary);
        $workspace = proxy_route_list_workspace_on_node($workspaceNode);
        ProxyRoute::factory()->create([
            'node_id' => $workspaceNode->id,
            'app_id' => $workspace->app_id,
            'instance_id' => $workspace->instance_id,
            'workspace_id' => $workspace->id,
            'domain' => 'feature-docs.test',
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $workspaceNode->id,
            'domain' => 'custom.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
        ]);
        $routeIntentBefore = ProxyRoute::query()
            ->orderBy('id')
            ->pluck('source_hash', 'id')
            ->all();

        $broadResponse = $this->call(
            'GET',
            '/api/proxy-routes',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROXY_ROUTE_LIST_CALLER_WG_IP],
        );
        $explicitResponse = $this->call(
            'GET',
            '/api/proxy-routes?filter=workspace',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROXY_ROUTE_LIST_CALLER_WG_IP],
        );

        $broadResponse
            ->assertOk()
            ->assertJsonPath('success.meta.count', 1)
            ->assertJsonPath('success.data.routes.0.domain', 'custom.test');
        $explicitResponse
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'workspace.unsupported_for_production');

        expect(ProxyRoute::query()->orderBy('id')->pluck('source_hash', 'id')->all())
            ->toBe($routeIntentBefore);
    })->with([
        'app-prod consumer with a legacy proxy wildcard grant' => ['consumer'],
        'legacy workspace route placed on app-prod' => ['target'],
    ]);

    it('rejects a legacy workspace-owned route served by app-prod when its workspace relation is missing', function (): void {
        $caller = createProxyRouteListCallerNode();
        assignProxyRouteListRole($caller);
        $workspaceNode = Node::factory()->appProd()->create(['name' => 'workspace-prod']);
        $app = App::factory()->create();
        $instance = Instance::factory()->for($app)->create();

        persist_made_proxy_route_bypassing_owner_guard(ProxyRoute::factory()->make([
            'node_id' => $workspaceNode->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'workspace_id' => null,
            'domain' => 'orphaned-workspace.test',
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]));
        ProxyRoute::factory()->create([
            'node_id' => $workspaceNode->id,
            'domain' => 'custom.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
        ]);
        $routeIntentBefore = ProxyRoute::query()
            ->orderBy('id')
            ->pluck('source_hash', 'id')
            ->all();

        $broadResponse = $this->call(
            'GET',
            '/api/proxy-routes',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROXY_ROUTE_LIST_CALLER_WG_IP],
        );
        $explicitResponse = $this->call(
            'GET',
            '/api/proxy-routes?filter=workspace',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROXY_ROUTE_LIST_CALLER_WG_IP],
        );

        $broadResponse
            ->assertOk()
            ->assertJsonPath('success.meta.count', 1)
            ->assertJsonPath('success.data.routes.0.domain', 'custom.test');
        $explicitResponse
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'workspace.unsupported_for_production');

        expect(ProxyRoute::query()->orderBy('id')->pluck('source_hash', 'id')->all())
            ->toBe($routeIntentBefore);
    });

    it('returns validation failures for invalid filters and node scopes', function (
        string $query,
        string $field,
    ): void {
        $caller = createProxyRouteListCallerNode();
        assignProxyRouteListRole($caller);

        $response = $this->call(
            'GET',
            "/api/proxy-routes?{$query}",
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROXY_ROUTE_LIST_CALLER_WG_IP],
        );

        $response
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', $field);
    })->with([
        'invalid filter' => ['filter=bad', 'filter'],
        'unknown node' => ['node=missing', 'node'],
    ]);

    it('does not grant route visibility to unassigned callers', function (): void {
        createProxyRouteListCallerNode();

        ProxyRoute::factory()->count(2)->create();

        $response = $this->call(
            'GET',
            '/api/proxy-routes',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROXY_ROUTE_LIST_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'proxy:read');
    });

    it('returns authorization failure when the caller has no route visibility', function (): void {
        $caller = createProxyRouteListCallerNode();
        assignProxyRouteListRole($caller, 'app-dev');

        $response = $this->call(
            'GET',
            '/api/proxy-routes',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROXY_ROUTE_LIST_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'proxy:read');
    });
});
