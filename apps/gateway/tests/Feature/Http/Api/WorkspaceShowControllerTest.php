<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Apps\AppInstanceDriver;
use App\Enums\ProcessCrashNotification;
use App\Enums\ProcessRestartPolicy;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Models\Project;
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
            'agent_ide_config' => ['adapter' => 'opencode'],
        ]);
        assignWorkspaceShowRole($node);
        grantWorkspaceShowAccess($caller, $node);
        $app = Project::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'domain' => null,
            'php_version' => '8.5',
        ]);
        $workspace = Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'path' => '/home/orbit/apps/docs/.worktrees/feature-docs',
            'agent_ide' => 'opencode',
            'agent_ide_workspace_id' => null,
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
            ->assertJsonPath('success.data.workspace.project', 'docs')
            ->assertJsonPath('success.data.workspace.node', 'app-1')
            ->assertJsonPath('success.data.workspace.path', '/home/orbit/apps/docs/.worktrees/feature-docs')
            ->assertJsonPath('success.data.workspace.php_version', '8.5')
            ->assertJsonPath('success.data.workspace.php_inherited', true)
            ->assertJsonPath('success.data.workspace.agent_ide.adapter', 'opencode')
            ->assertJsonPath('success.data.workspace.agent_ide.workspace_id', null)
            ->assertJsonPath('success.data.workspace.adopted', false)
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
            ->not->toHaveKey('latest_setup_run')->and($ws['agent_ide'])
            ->not->toHaveKey('inherited_from');
    });

    it('returns ambiguous name errors when app is omitted', function (): void {
        $gateway = createWorkspaceShowCallerNode();
        assignWorkspaceShowRole($gateway, 'gateway');
        $firstNode = Node::factory()->create(['name' => 'app-1']);
        $secondNode = Node::factory()->create(['name' => 'app-2']);
        assignWorkspaceShowRole($firstNode);
        assignWorkspaceShowRole($secondNode);
        $docs = Project::factory()->create(['name' => 'docs', 'node_id' => $firstNode->id]);
        $api = Project::factory()->create(['name' => 'api', 'node_id' => $secondNode->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $docs->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $api->id]);

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

        $app = Project::factory()->create([
            'name' => 'happie',
            'node_id' => $canonicalNode->id,
            'domain' => 'happie.test',
            'path' => '/home/nckrtl/apps/happie',
        ]);
        $instance = AppInstance::factory()
            ->for($app)
            ->create([
                'name' => 'nmbp',
                'driver' => AppInstanceDriver::Orbit,
                'driver_config' => new OrbitAppInstanceDriverConfigData(
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
            'app_instance_id' => $instance->id,
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
            ->assertJsonPath('success.data.workspace.project', 'happie')
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
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
        AppInstance::factory()->for($app)->create(['name' => 'production']);

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
            ->assertJsonPath('error.meta.project', 'docs');
        expect($response->json('error.meta'))->not->toHaveKey('instances');
    });

    it('requires an explicit bare app selector to resolve one instance by workspace path', function (): void {
        $gateway = createWorkspaceShowCallerNode();
        assignWorkspaceShowRole($gateway, 'gateway');
        $node = Node::factory()->create(['name' => 'app-1']);
        assignWorkspaceShowRole($node);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'path' => '/srv/docs/.worktrees/feature-docs',
        ]);
        AppInstance::factory()->for($app)->create(['name' => 'production']);

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
            ->assertJsonPath('error.meta.project', 'docs');
        expect($response->json('error.meta'))->not->toHaveKey('instances');
    });

    it('does not disclose hidden app instances when resolving by workspace name', function (): void {
        $caller = createWorkspaceShowCallerNode();
        $visibleNode = Node::factory()->create(['name' => 'visible-app']);
        $hiddenNode = Node::factory()->create(['name' => 'hidden-app']);
        assignWorkspaceShowRole($visibleNode);
        assignWorkspaceShowRole($hiddenNode);
        grantWorkspaceShowAccess($caller, $visibleNode);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $hiddenNode->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
        AppInstance::factory()->for($app)->create(['name' => 'production']);

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
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $hiddenNode->id]);
        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'path' => '/srv/docs/.worktrees/feature-docs',
        ]);
        AppInstance::factory()->for($app)->create(['name' => 'production']);

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
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $workspace = Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'path' => '/srv/docs/.worktrees/feature-docs',
        ]);
        AppInstance::factory()->for($app)->create(['name' => 'production']);

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
            ->assertJsonPath('success.data.workspace.project', 'docs')
            ->assertJsonPath('success.data.workspace.instance', $workspace->appInstance?->name);
    });

    it('resolves a visible workspace by path prefix', function (): void {
        $caller = createWorkspaceShowCallerNode();
        $node = Node::factory()->create(['name' => 'app-1']);
        assignWorkspaceShowRole($node);
        grantWorkspaceShowAccess($caller, $node);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
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
        $app = Project::factory()->create(['node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'hidden', 'app_id' => $app->id]);

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
        $app = Project::factory()->create(['node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'hidden', 'app_id' => $app->id]);

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
