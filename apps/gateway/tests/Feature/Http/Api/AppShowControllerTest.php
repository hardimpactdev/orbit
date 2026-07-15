<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Models\App;
use App\Models\AppDependencyAuditSummary;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
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

describe('AppShowController', function (): void {
    it('returns app registry details by name', function (): void {
        $caller = createAppShowCallerNode();
        $node = createTestAppHostNode(['name' => 'app-1', 'host' => '10.6.0.7']);
        grantAppShowAccess($caller, $node);

        App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'domain' => 'docs.example.com',
            'path' => '/srv/docs',
            'document_root' => 'public',
            'repository' => 'git@github.com:orbit/docs.git',
            'php_version' => '8.5',
            'adopted' => false,
        ]);

        $response = $this->call('GET', '/api/apps/docs', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP]);

        $response
            ->assertOk()
            ->assertJsonPath('success.data.app.name', 'docs')
            ->assertJsonPath('success.data.app.node', 'app-1')
            ->assertJsonPath('success.data.app.url', 'https://docs.example.com')
            ->assertJsonPath('success.data.app.runtime', 'php')
            ->assertJsonPath('success.data.app.runtime_config.proxy_transport', 'http')
            ->assertJsonPath('success.data.app.dependency_audit_status', 'unknown')
            ->assertJsonPath('success.data.app.dependency_warning_count', 0)
            ->assertJsonPath('success.data.app.dependency_danger_count', 0)
            ->assertJsonPath('success.data.app.last_dependency_audit_at', null)
            ->assertJsonPath('success.data.details.domain', 'docs.example.com')
            ->assertJsonPath('success.data.details.document_root', '/srv/docs/public')
            ->assertJsonPath('success.data.details.node.name', 'app-1')
            ->assertJsonPath('success.data.details.node.host', '10.6.0.7')
            ->assertJsonPath('success.data.details.dependency_audits', [])
            ->assertJsonPath('success.data.details.workspaces', [])
            ->assertJsonPath('success.data.details.processes', [])
            ->assertJsonPath('success.data.details.routes.0.host', 'docs.example.com');
    });

    it('returns dependency audit posture details by app name', function (): void {
        $caller = createAppShowCallerNode();
        $node = createTestAppHostNode(['name' => 'app-1']);
        grantAppShowAccess($caller, $node);

        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
        ]);
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
        $app = App::factory()->for($node, 'node')->create(['name' => 'docs']);
        $development = AppInstance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $node->id),
        ]);
        $production = AppInstance::factory()->for($app)->create([
            'name' => 'production',
            'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $node->id),
        ]);
        Process::factory()
            ->forOwner($app, $node)
            ->create([
                'app_instance_id' => $development->id,
                'name' => 'queue',
            ]);
        Process::factory()
            ->forOwner($app, $node)
            ->create([
                'app_instance_id' => $production->id,
                'name' => 'queue',
            ]);

        $this
            ->call('GET', '/api/apps/docs', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP])
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'queue',
                'app_instance' => 'development',
                'runtime' => 'systemd',
            ])
            ->assertJsonFragment([
                'name' => 'queue',
                'app_instance' => 'production',
                'runtime' => 'systemd',
            ]);
    });

    it('resolves by hostname when no app name matches', function (): void {
        $caller = createAppShowCallerNode();
        $node = createTestAppHostNode();
        grantAppShowAccess($caller, $node);

        App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'domain' => 'docs.example.com']);

        $response = $this->call(
            'GET',
            '/api/apps/docs.example.com',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP],
        );

        $response->assertOk()
            ->assertJsonPath('success.data.app.name', 'docs');
    });

    it('prefers app name over hostname collisions', function (): void {
        $caller = createAppShowCallerNode();
        $node = createTestAppHostNode();
        grantAppShowAccess($caller, $node);

        App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'domain' => 'docs.example.com']);
        App::factory()->create(['name' => 'docs.example.com', 'node_id' => $node->id, 'domain' => 'other.example.com']);

        $response = $this->call(
            'GET',
            '/api/apps/docs.example.com',
            [],
            [],
            [],
            ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP],
        );

        $response->assertOk()
            ->assertJsonPath('success.data.app.name', 'docs.example.com');
    });

    it('rejects hidden apps when the caller lacks app:read on the owning node', function (): void {
        $caller = createAppShowCallerNode();
        $node = createTestAppHostNode();
        grantAppShowAccess($caller, $node, ['node:read']);
        App::factory()->create(['name' => 'hidden', 'node_id' => $node->id]);

        $response = $this->call('GET', '/api/apps/hidden', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP]);

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:read')
            ->assertJsonPath('error.meta.serving_node', $node->name);
    });

    it('authorizes hostname selectors against the owning node', function (): void {
        $caller = createAppShowCallerNode();
        $node = createTestAppHostNode();
        grantAppShowAccess($caller, $node, ['node:read']);
        App::factory()->create(['name' => 'hidden', 'node_id' => $node->id, 'domain' => 'hidden.example.com']);

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
            ->assertJsonPath('error.meta.serving_node', $node->name);
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

        App::factory()->create(['name' => 'owned', 'node_id' => $caller->id]);
        App::factory()->create(['name' => 'hidden', 'node_id' => $otherNode->id]);

        $response = $this->call('GET', '/api/apps/owned', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP]);

        $response
            ->assertOk()
            ->assertJsonPath('success.data.app.name', 'owned');

        $hidden = $this->call('GET', '/api/apps/hidden', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP]);

        $hidden
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:read')
            ->assertJsonPath('error.meta.serving_node', $otherNode->name);
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
        $node = createTestAppHostNode();
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        $response = $this->call('GET', '/api/apps/docs', [], [], [], ['REMOTE_ADDR' => APP_SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.app.name', 'docs');
    });

    it('rejects unauthenticated requests', function (): void {
        $response = $this->getJson('/api/apps/docs');

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });
});
