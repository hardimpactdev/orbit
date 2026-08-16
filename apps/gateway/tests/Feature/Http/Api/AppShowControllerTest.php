<?php

declare(strict_types=1);

use App\Data\Apps\LaravelCloudInstanceDriverConfigData;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\InstanceDriver;
use App\Models\App;
use App\Models\AppDependencyAuditSummary;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Nodes\Access\NodePermissionPresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const APP_SHOW_CALLER_WG_IP = '10.6.0.98';

function createAppShowCallerNode(array $overrides = [], ?string $role = null): Node
{
    $node = Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => APP_SHOW_CALLER_WG_IP,
        'wireguard_address' => APP_SHOW_CALLER_WG_IP,
    ], $overrides));

    if ($role !== null) {
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    return $node;
}

/**
 * @param  list<string>  $permissions
 */
function grantAppShowAccess(Node $caller, Node $appNode, array $permissions = ['app:read']): void
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

function create_app_show_instance(
    App $app,
    Node $node,
    string $name = 'development',
    ?string $path = null,
    string $documentRoot = 'public',
    ?string $domain = null,
): Instance {
    return Instance::factory()->for($app)->create([
        'name' => $name,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: $path ?? '/home/orbit/apps/'.$app->name,
            document_root: $documentRoot,
            domain: $domain,
        ),
    ]);
}

describe('AppShowController', function (): void {
    it('returns app registry details by name', function (): void {
        $caller = createAppShowCallerNode();
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantAppShowAccess($caller, $node);

        $app = App::factory()->create([
            'name' => 'docs',
            'repository' => 'git@github.com:orbit/docs.git',
            'php_version' => '8.5',
        ]);
        $instance = create_app_show_instance($app, $node, 'development', '/srv/docs', 'public', 'docs.example.com');
        Workspace::factory()->for($app)->create([
            'name' => 'feature-docs',
            'instance_id' => $instance->id,
        ]);

        $response = $this->call('GET', '/api/apps/docs', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP]);

        $response
            ->assertOk()
            ->assertJsonPath('success.data.app.name', 'docs')
            ->assertJsonPath('success.data.app.runtime', 'php')
            ->assertJsonPath('success.data.app.runtime_config.proxy_transport', 'http')
            ->assertJsonPath('success.data.app.dependency_audit_status', 'unknown')
            ->assertJsonPath('success.data.app.dependency_warning_count', 0)
            ->assertJsonPath('success.data.app.dependency_danger_count', 0)
            ->assertJsonPath('success.data.app.last_dependency_audit_at', null)
            ->assertJsonPath('success.data.details.dependency_audits', [])
            ->assertJsonPath('success.data.details.instances.0.name', 'development')
            ->assertJsonPath('success.data.details.instances.0.driver', 'orbit')
            ->assertJsonPath('success.data.details.instances.0.node', 'app-1')
            ->assertJsonPath('success.data.details.instances.0.url', 'https://docs.example.com')
            ->assertJsonPath('success.data.details.instances.0.path', '/srv/docs')
            ->assertJsonPath('success.data.details.instances.0.root', 'public')
            ->assertJsonPath('success.data.details.instances.0.domain', 'docs.example.com')
            ->assertJsonPath('success.data.details.instances.0.workspaces.0.name', 'feature-docs')
            ->assertJsonPath(
                'success.data.details.instances.0.workspaces.0.url',
                'https://feature-docs.docs.example.com',
            )
            ->assertJsonPath('success.data.details.processes', [])
            ->assertJsonPath('success.data.details.routes.0.host', 'docs.example.com')
            ->assertJsonMissingPath('success.data.app.node')
            ->assertJsonMissingPath('success.data.app.url')
            ->assertJsonMissingPath('success.data.app.path')
            ->assertJsonMissingPath('success.data.app.root')
            ->assertJsonMissingPath('success.data.details.domain')
            ->assertJsonMissingPath('success.data.details.document_root')
            ->assertJsonMissingPath('success.data.details.node')
            ->assertJsonMissingPath('success.data.details.workspaces');
    });

    it('returns dependency audit posture details by app name', function (): void {
        $caller = createAppShowCallerNode();
        $node = createTestAppHostNode(['name' => 'app-1']);
        grantAppShowAccess($caller, $node);

        $app = App::factory()->create([
            'name' => 'docs',
        ]);
        create_app_show_instance($app, $node);
        AppDependencyAuditSummary::factory()
            ->findings()
            ->create([
                'app_id' => $app->id,
                'audited_at' => '2026-07-02 08:15:00',
                'advisory_summary' => [
                    [
                        'package_name' => 'guzzlehttp/guzzle',
                        'severity' => 'high',
                        'title' => 'Example advisory',
                    ],
                ],
            ]);

        $response = $this->call('GET', '/api/apps/docs', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP]);

        $response
            ->assertOk()
            ->assertJsonPath('success.data.app.dependency_audit_status', 'findings')
            ->assertJsonPath('success.data.app.dependency_warning_count', 14)
            ->assertJsonPath('success.data.app.dependency_danger_count', 2)
            ->assertJsonPath('success.data.app.last_dependency_audit_at', '2026-07-02T08:15:00+00:00')
            ->assertJsonPath('success.data.details.dependency_audits.0.manager', 'composer')
            ->assertJsonPath('success.data.details.dependency_audits.0.status', 'findings')
            ->assertJsonPath('success.data.details.dependency_audits.0.severity_counts.high', 2)
            ->assertJsonPath('success.data.details.dependency_audits.0.severity_counts.medium', 14)
            ->assertJsonPath(
                'success.data.details.dependency_audits.0.advisory_summary.0.package_name',
                'guzzlehttp/guzzle',
            );
    });

    it('returns app process definitions with concrete instance identity', function (): void {
        $caller = createAppShowCallerNode();
        $node = createTestAppHostNode(['name' => 'app-1']);
        grantAppShowAccess($caller, $node);
        $app = App::factory()->create(['name' => 'docs']);
        $development = Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(node_id: $node->id),
        ]);
        $production = Instance::factory()->for($app)->create([
            'name' => 'production',
            'driver_config' => new OrbitInstanceDriverConfigData(node_id: $node->id),
        ]);
        Process::factory()
            ->forOwner($app, $node)
            ->create([
                'instance_id' => $development->id,
                'name' => 'queue',
            ]);
        Process::factory()
            ->forOwner($app, $node)
            ->create([
                'instance_id' => $production->id,
                'name' => 'queue',
            ]);

        $this
            ->call('GET', '/api/apps/docs', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP])
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'queue',
                'instance' => 'development',
                'runtime' => 'systemd',
            ])
            ->assertJsonFragment([
                'name' => 'queue',
                'instance' => 'production',
                'runtime' => 'systemd',
            ]);
    });

    it('returns only instances and workspaces placed on visible serving nodes', function (): void {
        $caller = createAppShowCallerNode();
        $visibleNode = createTestAppHostNode(['name' => 'visible-node', 'tld' => 'visible']);
        $hiddenNode = createTestAppHostNode(['name' => 'hidden-node', 'tld' => 'hidden']);
        grantAppShowAccess($caller, $visibleNode);

        $app = App::factory()->create([
            'name' => 'docs',
        ]);
        $visibleInstance = Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $visibleNode->id,
                node: $visibleNode->name,
                domain: 'docs.visible',
            ),
        ]);
        $hiddenInstance = Instance::factory()->for($app)->create([
            'name' => 'production',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $hiddenNode->id,
                node: $hiddenNode->name,
                domain: 'docs.hidden',
            ),
        ]);
        Workspace::factory()->for($app)->create([
            'name' => 'visible-workspace',
            'instance_id' => $visibleInstance->id,
        ]);
        Workspace::factory()->for($app)->create([
            'name' => 'hidden-workspace',
            'instance_id' => $hiddenInstance->id,
        ]);

        $response = $this->call(
            'GET',
            '/api/apps/docs',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP,
            ],
        );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'success.data.details.instances')
            ->assertJsonPath('success.data.details.instances.0.name', 'development')
            ->assertJsonPath('success.data.details.instances.0.node', 'visible-node')
            ->assertJsonPath('success.data.details.instances.0.url', 'https://docs.visible')
            ->assertJsonPath(
                'success.data.details.instances.0.workspaces.0.url',
                'https://visible-workspace.docs.visible',
            )
            ->assertJsonMissingPath('success.data.details.workspaces')
            ->assertJsonMissing(['name' => 'production'])
            ->assertJsonMissing(['name' => 'hidden-workspace']);
    });

    it('omits workspace details for app production callers and production placements', function (): void {
        $caller = createAppShowCallerNode(role: 'app-prod');
        $developmentNode = createTestAppHostNode(['name' => 'app-dev-1']);
        grantAppShowAccess($caller, $developmentNode);
        $developmentApp = App::factory()->create(['name' => 'docs']);
        $developmentInstance = create_app_show_instance($developmentApp, $developmentNode);
        Workspace::factory()->for($developmentApp)->create([
            'name' => 'feature-docs',
            'instance_id' => $developmentInstance->id,
        ]);

        $this
            ->call('GET', '/api/apps/docs', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP])
            ->assertOk()
            ->assertJsonPath('success.data.details.instances.0.workspaces', [])
            ->assertJsonMissingPath('success.data.details.workspaces');

        $caller->roleAssignments()->delete();
        NodeRoleAssignment::factory()->create([
            'node_id' => $caller->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);
        $productionNode = createTestAppHostNode(['name' => 'app-prod-1'], 'app-prod');
        $productionApp = App::factory()->create(['name' => 'shop']);
        $productionInstance = create_app_show_instance($productionApp, $productionNode, 'production');
        Workspace::factory()->for($productionApp)->create([
            'name' => 'legacy-workspace',
            'instance_id' => $productionInstance->id,
        ]);

        $this
            ->call('GET', '/api/apps/shop', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP])
            ->assertOk()
            ->assertJsonPath('success.data.details.instances.0.workspaces', [])
            ->assertJsonMissingPath('success.data.details.workspaces');
    });

    it('lets gateway callers inspect external instances with configured URLs', function (): void {
        createAppShowCallerNode(role: 'gateway');
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs']);
        Instance::factory()->for($app)->create([
            'name' => 'production',
            'driver' => InstanceDriver::LaravelCloud,
            'driver_config' => new LaravelCloudInstanceDriverConfigData(
                application_name: 'docs',
                environment_name: 'production',
                domain: 'docs.example.com',
            ),
        ]);

        $response = $this->call(
            'GET',
            '/api/apps/docs',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP,
            ],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.details.instances.0.name', 'production')
            ->assertJsonPath('success.data.details.instances.0.driver', 'laravel-cloud')
            ->assertJsonPath('success.data.details.instances.0.node', null)
            ->assertJsonPath('success.data.details.instances.0.url', 'https://docs.example.com');
    });

    it('lets gateway callers resolve external instances by hostname', function (): void {
        createAppShowCallerNode(role: 'gateway');
        $app = App::factory()->create(['name' => 'docs']);
        Instance::factory()->for($app)->create([
            'name' => 'production',
            'driver' => InstanceDriver::LaravelCloud,
            'driver_config' => new LaravelCloudInstanceDriverConfigData(
                application_name: 'docs',
                environment_name: 'production',
                domain: 'docs.example.com',
            ),
        ]);

        $this
            ->call('GET', '/api/apps/docs.example.com', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP])
            ->assertOk()
            ->assertJsonPath('success.data.app.name', 'docs');
    });

    it('resolves by hostname when no app name matches', function (): void {
        $caller = createAppShowCallerNode();
        $node = createTestAppHostNode();
        grantAppShowAccess($caller, $node);

        $app = App::factory()->create([
            'name' => 'docs',
        ]);
        create_app_show_instance($app, $node, 'development', null, 'public', 'docs.example.com');

        $response = $this->call(
            'GET',
            '/api/apps/docs.example.com',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP],
        );

        $response->assertOk()->assertJsonPath('success.data.app.name', 'docs');
    });

    it('prefers app name over hostname collisions', function (): void {
        $caller = createAppShowCallerNode();
        $node = createTestAppHostNode();
        grantAppShowAccess($caller, $node);

        $hostnameApp = App::factory()->create([
            'name' => 'docs',
        ]);
        $nameApp = App::factory()->create([
            'name' => 'docs.example.com',
        ]);
        create_app_show_instance($hostnameApp, $node, 'development', null, 'public', 'docs.example.com');
        create_app_show_instance($nameApp, $node, 'development', null, 'public', 'other.example.com');

        $response = $this->call(
            'GET',
            '/api/apps/docs.example.com',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP],
        );

        $response->assertOk()->assertJsonPath('success.data.app.name', 'docs.example.com');
    });

    it('rejects hidden apps when the caller lacks app:read on the owning node', function (): void {
        $caller = createAppShowCallerNode();
        $node = createTestAppHostNode();
        grantAppShowAccess($caller, $node, ['node:read']);
        $app = App::factory()->create(['name' => 'hidden']);
        create_app_show_instance($app, $node);

        $response = $this->call('GET', '/api/apps/hidden', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP]);

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:read')
            ->assertJsonMissingPath('error.meta.serving_node');
    });

    it('authorizes hostname selectors against the owning node', function (): void {
        $caller = createAppShowCallerNode();
        $node = createTestAppHostNode();
        grantAppShowAccess($caller, $node, ['node:read']);
        $app = App::factory()->create([
            'name' => 'hidden',
        ]);
        create_app_show_instance($app, $node, 'development', null, 'public', 'hidden.example.com');

        $response = $this->call(
            'GET',
            '/api/apps/hidden.example.com',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:read')
            ->assertJsonMissingPath('error.meta.serving_node');
    });

    it('lets an app role node show only its own app registry rows through its self grant', function (): void {
        $caller = createAppShowCallerNode([
            'name' => 'dev-1',
            'host' => APP_SHOW_CALLER_WG_IP,
            'wireguard_address' => APP_SHOW_CALLER_WG_IP,
        ], role: 'app-dev');
        $otherNode = createTestAppHostNode(['name' => 'dev-2']);

        grantAppShowAccess(
            caller: $caller,
            appNode: $caller,
            permissions: app(NodePermissionPresets::class)->permissions('app-dev-self'),
        );

        $ownedApp = App::factory()->create(['name' => 'owned']);
        $hiddenApp = App::factory()->create(['name' => 'hidden']);
        create_app_show_instance($ownedApp, $caller);
        create_app_show_instance($hiddenApp, $otherNode);

        $response = $this->call('GET', '/api/apps/owned', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP]);

        $response->assertOk()->assertJsonPath('success.data.app.name', 'owned');

        $hidden = $this->call('GET', '/api/apps/hidden', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP]);

        $hidden
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:read')
            ->assertJsonMissingPath('error.meta.serving_node');
    });

    it('returns not found for absent apps', function (): void {
        createAppShowCallerNode();

        $response = $this->call('GET', '/api/apps/missing', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP]);

        $response
            ->assertNotFound()
            ->assertJsonPath('error.code', 'app.not_found')
            ->assertJsonPath('error.message', "App 'missing' not found.");
    });

    it('lets gateway callers inspect any app', function (): void {
        createAppShowCallerNode(role: 'gateway');
        App::factory()->create(['name' => 'docs']);

        $response = $this->call('GET', '/api/apps/docs', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP]);

        $response->assertOk()->assertJsonPath('success.data.app.name', 'docs');
    });

    it('rejects unauthenticated requests', function (): void {
        $response = $this->getJson('/api/apps/docs');

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });
});
