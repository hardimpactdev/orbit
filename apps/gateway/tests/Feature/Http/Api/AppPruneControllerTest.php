<?php

declare(strict_types=1);

use App\Contracts\AgentIdeMessageAdapter;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const APP_PRUNE_CALLER_WG_IP = '10.6.0.97';

function createAppPruneCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => APP_PRUNE_CALLER_WG_IP,
        'wireguard_address' => APP_PRUNE_CALLER_WG_IP,
    ], $overrides));
}

/**
 * @param  list<string>  $permissions
 */
function grantAppPruneAccess(Node $caller, Node $appNode, array $permissions = ['instance:prune']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function createAppPruneInstance(Project $project, Node $node): AppInstance
{
    return AppInstance::factory()->for($project)->create([
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            path: $project->path,
            document_root: $project->document_root,
            domain: $project->domain,
        ),
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);
}

beforeEach(function (): void {
    app()->instance(AgentIdeMessageAdapter::class, new AppPruneControllerAdapter);
});

describe('AppPruneController', function (): void {
    it('prunes stale workspaces for callers with instance:prune on the app node', function (): void {
        $caller = createAppPruneCallerNode();
        $appNode = Node::factory()->appDev()->create(['name' => 'app-1']);
        grantAppPruneAccess($caller, $appNode);
        $app = Project::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
        ]);
        $instance = createAppPruneInstance($app, $appNode);
        Workspace::factory()->create([
            'name' => 'stale-ws',
            'app_id' => $app->id,
            'app_instance_id' => $instance->id,
        ]);

        $response = $this->call(
            'POST',
            '/api/instances/prune',
            [
                'instance' => 'docs',
                'dry_run' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => APP_PRUNE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.project', 'docs')
            ->assertJsonPath('success.data.instance', 'development')
            ->assertJsonPath('success.data.stale_workspaces.0.name', 'stale-ws')
            ->assertJsonPath('success.data.stale_workspaces.0.removed', false)
            ->assertJsonPath('success.data.dry_run', true);
    });

    it('rejects callers without instance:prune on the app node', function (): void {
        $caller = createAppPruneCallerNode();
        $appNode = Node::factory()->appDev()->create(['name' => 'app-1']);
        grantAppPruneAccess($caller, $appNode, ['instance:read']);
        $project = Project::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
        ]);
        createAppPruneInstance($project, $appNode);

        $response = $this->call(
            'POST',
            '/api/instances/prune',
            [
                'instance' => 'docs',
                'dry_run' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => APP_PRUNE_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'instance:prune')
            ->assertJsonPath('error.meta.serving_node', 'app-1');
    });

    it('rejects app production callers and production targets before workspace discovery', function (): void {
        $caller = createAppPruneCallerNode();
        NodeRoleAssignment::factory()->create([
            'node_id' => $caller->id,
            'role' => 'app-prod',
            'status' => 'active',
        ]);
        $developmentNode = Node::factory()->appDev()->create(['name' => 'app-dev-1']);
        grantAppPruneAccess($caller, $developmentNode);
        $developmentProject = Project::factory()->for($developmentNode, 'node')->create([
            'name' => 'docs',
        ]);
        createAppPruneInstance($developmentProject, $developmentNode);
        $adapter = new AppPruneControllerAdapter;
        app()->instance(AgentIdeMessageAdapter::class, $adapter);

        $this
            ->call(
                'POST',
                '/api/instances/prune',
                ['instance' => 'docs', 'dry_run' => true],
                [],
                [],
                ['REMOTE_ADDR' => APP_PRUNE_CALLER_WG_IP],
            )
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'workspace.unsupported_for_production');

        expect($adapter->workspaceCalls)->toBe(0);

        $caller->roleAssignments()->delete();
        NodeRoleAssignment::factory()->create([
            'node_id' => $caller->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);
        $productionNode = Node::factory()->appProd()->create(['name' => 'app-prod-1']);
        $productionProject = Project::factory()->for($productionNode, 'node')->create([
            'name' => 'shop',
        ]);
        createAppPruneInstance($productionProject, $productionNode);

        $this
            ->call(
                'POST',
                '/api/instances/prune',
                ['instance' => 'shop', 'dry_run' => true],
                [],
                [],
                ['REMOTE_ADDR' => APP_PRUNE_CALLER_WG_IP],
            )
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'workspace.unsupported_for_production');

        expect($adapter->workspaceCalls)->toBe(0);
    });
});

final class AppPruneControllerAdapter implements AgentIdeMessageAdapter
{
    public int $workspaceCalls = 0;

    public function activeSession(array $target, string $adapter): ?array
    {
        return null;
    }

    public function deliver(array $target, string $adapter, array $session, string $message): array
    {
        return ['status' => 'failed'];
    }

    public function workspaces(array $target, string $adapter): array
    {
        $this->workspaceCalls++;

        return [];
    }
}
