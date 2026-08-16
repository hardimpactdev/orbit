<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\InstanceDriver;
use App\Enums\ProcessCrashNotification;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const WORKSPACE_SHOW_CALLER_WG_IP = '10.6.0.96';

function createWorkspaceShowCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => WORKSPACE_SHOW_CALLER_WG_IP,
        'wireguard_address' => WORKSPACE_SHOW_CALLER_WG_IP,
    ], $overrides));
}

function grantWorkspaceShowAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['workspace:read'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function assignWorkspaceShowRole(Node $node, string $role = 'app-dev'): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => $role === 'app-dev' ? ['tld' => 'test'] : [],
    ]);
}

describe('WorkspaceShowController', function (): void {
    it('returns registry details for a visible workspace', function (): void {
        $caller = createWorkspaceShowCallerNode();
        $node = Node::factory()->create([
            'name' => 'app-1',
            'host' => '1.2.3.4',
            'tld' => 'test',
        ]);
        assignWorkspaceShowRole($node);
        grantWorkspaceShowAccess($caller, $node);
        $app = App::factory()
            ->placedOn($node)
            ->create([
                'name' => 'docs',
                'php_version' => '8.5',
            ]);
        $workspace = Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'instance_id' => $app->instances()->firstOrFail()->id,
            'path' => '/home/orbit/apps/docs/.worktrees/feature-docs',
            'adopted' => true,
        ]);

        Process::factory()
            ->forOwner($app)
            ->create([
                'name' => 'vite',
                'command' => 'npm run dev',
                'restart_policy' => ProcessRestartPolicy::Always,
                'crash_notification' => ProcessCrashNotification::None,
                'sort_order' => 1,
            ]);

        $response = $this->call(
            'GET',
            '/api/workspaces/feature-docs?instance=docs',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_SHOW_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.meta.registry_only', true)
            // canonical workspace entity
            ->assertJsonPath('success.data.workspace.name', 'feature-docs')
            ->assertJsonPath('success.data.workspace.app', 'docs')
            ->assertJsonPath('success.data.workspace.node', 'app-1')
            ->assertJsonPath('success.data.workspace.path', '/home/orbit/apps/docs/.worktrees/feature-docs')
            ->assertJsonPath('success.data.workspace.php_version', '8.5')
            ->assertJsonPath('success.data.workspace.php_inherited', true)
            ->assertJsonPath('success.data.workspace.adopted', true)
            ->assertJsonPath('success.data.workspace.lifecycle_status', 'expected')
            // show-only siblings
            ->assertJsonPath('success.data.node.name', 'app-1')
            ->assertJsonPath('success.data.node.host', '1.2.3.4')
            ->assertJsonPath('success.data.inherited_processes.0.name', 'vite')
            // node must be a string slug inside the entity
            ->assertJsonPath('success.data.workspace.node', 'app-1');
        $ws = $response->json('success.data.workspace');
        // absent legacy fields
        expect($ws)
            ->not->toHaveKey('branch')->and($ws)
            ->not->toHaveKey('runtime_expectations')->and($ws)
            ->not->toHaveKey('route')->and($ws)
            ->not->toHaveKey('latest_setup_run')->and($ws)
            ->not->toHaveKey('agent_ide');
    });

    it('returns ambiguous name errors when app is omitted', function (): void {
        $gateway = createWorkspaceShowCallerNode();
        assignWorkspaceShowRole($gateway, 'gateway');
        $firstNode = Node::factory()->create(['name' => 'app-1']);
        $secondNode = Node::factory()->create(['name' => 'app-2']);
        assignWorkspaceShowRole($firstNode);
        assignWorkspaceShowRole($secondNode);
        $docs = App::factory()->placedOn($firstNode)->create(['name' => 'docs']);
        $api = App::factory()->placedOn($secondNode)->create(['name' => 'api']);
        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $docs->id,
            'instance_id' => $docs->instances()->firstOrFail()->id,
        ]);
        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $api->id,
            'instance_id' => $api->instances()->firstOrFail()->id,
        ]);

        $response = $this->call(
            'GET',
            '/api/workspaces/feature-docs',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_SHOW_CALLER_WG_IP],
        );

        $response
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'workspace.ambiguous_name')
            ->assertJsonPath('error.meta.name', 'feature-docs')
            ->assertJsonPath('error.meta.instances', ['docs.development', 'api.development']);
    });

    it('returns instance-bound workspace details for app instance selectors', function (): void {
        $caller = createWorkspaceShowCallerNode();
        $canonicalNode = Node::factory()->create(['name' => 'beast', 'tld' => 'test']);
        $localNode = Node::factory()->create(['name' => 'NMBP', 'host' => 'nmbp', 'tld' => 'nmbp']);
        assignWorkspaceShowRole($canonicalNode);
        assignWorkspaceShowRole($localNode);
        grantWorkspaceShowAccess($caller, $localNode);

        $app = App::factory()->create([
            'name' => 'happie',
        ]);
        $instance = Instance::factory()
            ->for($app)
            ->create([
                'name' => 'nmbp',
                'driver' => InstanceDriver::Orbit,
                'driver_config' => new OrbitInstanceDriverConfigData(
                    node_id: $localNode->id,
                    node: 'NMBP',
                    path: '/Users/nckrtl/apps/happie',
                    document_root: 'public',
                    domain: 'happie.nmbp',
                ),
            ]);
        Workspace::factory()->create([
            'name' => 'recipes',
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'path' => '/Users/nckrtl/.codex/worktrees/a59f/happie',
        ]);

        $response = $this->call(
            'GET',
            '/api/workspaces/recipes?instance=happie.nmbp',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_SHOW_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.workspace.name', 'recipes')
            ->assertJsonPath('success.data.workspace.app', 'happie')
            ->assertJsonPath('success.data.workspace.instance', 'nmbp')
            ->assertJsonPath('success.data.workspace.node', 'NMBP')
            ->assertJsonPath('success.data.workspace.url', 'https://recipes.happie.nmbp')
            ->assertJsonPath('success.data.node.name', 'NMBP')
            ->assertJsonPath('success.data.node.host', 'nmbp');
    });

    it('requires an explicit bare app selector to resolve one instance by workspace name', function (): void {
        $gateway = createWorkspaceShowCallerNode();
        assignWorkspaceShowRole($gateway, 'gateway');
        $node = Node::factory()->create(['name' => 'app-1']);
        assignWorkspaceShowRole($node);
        $app = App::factory()->placedOn($node)->create(['name' => 'docs']);
        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'instance_id' => $app->instances()->firstOrFail()->id,
        ]);
        Instance::factory()->for($app)->create(['name' => 'production']);

        $response = $this->call(
            'GET',
            '/api/workspaces/feature-docs?instance=docs',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_SHOW_CALLER_WG_IP],
        );

        $response
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'instance')
            ->assertJsonPath('error.meta.reason', 'instance_required')
            ->assertJsonPath('error.meta.app', 'docs');
        expect($response->json('error.meta'))->not->toHaveKey('instances');
    });

    it('requires an explicit bare app selector to resolve one instance by workspace path', function (): void {
        $gateway = createWorkspaceShowCallerNode();
        assignWorkspaceShowRole($gateway, 'gateway');
        $node = Node::factory()->create(['name' => 'app-1']);
        assignWorkspaceShowRole($node);
        $app = App::factory()->placedOn($node)->create(['name' => 'docs']);
        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'instance_id' => $app->instances()->firstOrFail()->id,
            'path' => '/srv/docs/.worktrees/feature-docs',
        ]);
        Instance::factory()->for($app)->create(['name' => 'production']);

        $response = $this->call(
            'GET',
            '/api/workspaces/resolve-by-path?path=/srv/docs/.worktrees/feature-docs/app&instance=docs',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_SHOW_CALLER_WG_IP],
        );

        $response
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'instance')
            ->assertJsonPath('error.meta.reason', 'instance_required')
            ->assertJsonPath('error.meta.app', 'docs');
        expect($response->json('error.meta'))->not->toHaveKey('instances');
    });

    it('does not disclose hidden app instances when resolving by workspace name', function (): void {
        $caller = createWorkspaceShowCallerNode();
        $visibleNode = Node::factory()->create(['name' => 'visible-app']);
        $hiddenNode = Node::factory()->create(['name' => 'hidden-app']);
        assignWorkspaceShowRole($visibleNode);
        assignWorkspaceShowRole($hiddenNode);
        grantWorkspaceShowAccess($caller, $visibleNode);
        $app = App::factory()->placedOn($hiddenNode)->create(['name' => 'docs']);
        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'instance_id' => $app->instances()->firstOrFail()->id,
        ]);
        Instance::factory()->for($app)->create(['name' => 'production']);

        $response = $this->call(
            'GET',
            '/api/workspaces/feature-docs?instance=docs',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_SHOW_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
        expect($response->json('error.meta'))->not->toHaveKey('instances');
    });

    it('does not disclose hidden app instances when resolving by workspace path', function (): void {
        $caller = createWorkspaceShowCallerNode();
        $visibleNode = Node::factory()->create(['name' => 'visible-app']);
        $hiddenNode = Node::factory()->create(['name' => 'hidden-app']);
        assignWorkspaceShowRole($visibleNode);
        assignWorkspaceShowRole($hiddenNode);
        grantWorkspaceShowAccess($caller, $visibleNode);
        $app = App::factory()->placedOn($hiddenNode)->create(['name' => 'docs']);
        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'instance_id' => $app->instances()->firstOrFail()->id,
            'path' => '/srv/docs/.worktrees/feature-docs',
        ]);
        Instance::factory()->for($app)->create(['name' => 'production']);

        $response = $this->call(
            'GET',
            '/api/workspaces/resolve-by-path?path=/srv/docs/.worktrees/feature-docs/app&instance=docs',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_SHOW_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
        expect($response->json('error.meta'))->not->toHaveKey('instances');
    });

    it('resolves a workspace path through an explicit app instance selector', function (): void {
        $gateway = createWorkspaceShowCallerNode();
        assignWorkspaceShowRole($gateway, 'gateway');
        $node = Node::factory()->create(['name' => 'app-1']);
        assignWorkspaceShowRole($node);
        $app = App::factory()->placedOn($node)->create(['name' => 'docs']);
        $workspace = Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'path' => '/srv/docs/.worktrees/feature-docs',
        ]);
        Instance::factory()->for($app)->create(['name' => 'production']);

        $response = $this->call(
            'GET',
            '/api/workspaces/resolve-by-path?path=/srv/docs/.worktrees/feature-docs/app&instance=docs.development',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_SHOW_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.workspace.name', 'feature-docs')
            ->assertJsonPath('success.data.workspace.app', 'docs')
            ->assertJsonPath('success.data.workspace.instance', $workspace->instance?->name);
    });

    it('resolves a visible workspace by path prefix', function (): void {
        $caller = createWorkspaceShowCallerNode();
        $node = Node::factory()->create(['name' => 'app-1']);
        assignWorkspaceShowRole($node);
        grantWorkspaceShowAccess($caller, $node);
        $app = App::factory()->placedOn($node)->create(['name' => 'docs']);
        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'instance_id' => $app->instances()->firstOrFail()->id,
            'path' => '/srv/docs/.worktrees/feature-docs',
        ]);

        $response = $this->call(
            'GET',
            '/api/workspaces/resolve-by-path?path=/srv/docs/.worktrees/feature-docs/app',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_SHOW_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.workspace.name', 'feature-docs')
            ->assertJsonPath('success.meta.registry_only', true);
    });

    it('returns not found for hidden workspaces', function (): void {
        createWorkspaceShowCallerNode();
        $node = Node::factory()->create();
        assignWorkspaceShowRole($node);
        $app = App::factory()->placedOn($node)->create();
        Workspace::factory()->create([
            'name' => 'hidden',
            'app_id' => $app->id,
            'instance_id' => $app->instances()->firstOrFail()->id,
        ]);

        $response = $this->call(
            'GET',
            '/api/workspaces/hidden',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_SHOW_CALLER_WG_IP],
        );

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('does not grant fleet-wide visibility to unassigned callers', function (): void {
        createWorkspaceShowCallerNode();
        $node = Node::factory()->create();
        assignWorkspaceShowRole($node);
        $app = App::factory()->placedOn($node)->create();
        Workspace::factory()->create([
            'name' => 'hidden',
            'app_id' => $app->id,
            'instance_id' => $app->instances()->firstOrFail()->id,
        ]);

        $response = $this->call(
            'GET',
            '/api/workspaces/hidden',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_SHOW_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'workspace:read');
    });

    it('rejects unauthenticated requests', function (): void {
        $response = $this->getJson('/api/workspaces/feature-docs');

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });
});
