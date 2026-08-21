<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\InstanceDriver;
use App\Enums\WorkspaceLifecyclePhase;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\WorkspaceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const WORKSPACE_STEP_STORE_CALLER_WG_IP = '10.6.0.98';

function createWorkspaceStepStoreCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'step-store-caller',
        'host' => WORKSPACE_STEP_STORE_CALLER_WG_IP,
        'wireguard_address' => WORKSPACE_STEP_STORE_CALLER_WG_IP,
    ], $overrides);

    return match ($role) {
        'app-dev' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

function grantWorkspaceStepStoreAccess(Node $caller, Node $appNode, array $permissions = ['workspace:write']): void
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

function create_workspace_step_store_instance(App $app, Node $node, string $instanceName): Instance
{
    return Instance::factory()->create([
        'app_id' => $app->id,
        'name' => $instanceName,
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            path: '/home/orbit/apps/'.$app->name,
            domain: "{$app->name}.{$instanceName}",
        ),
    ]);
}

describe('WorkspaceStepStoreController', function (): void {
    it('rejects setup steps that directly consume the parent app env file', function (string $command): void {
        $caller = createWorkspaceStepStoreCallerNode();
        $node = createTestAppHostNode(['name' => 'NMBP', 'tld' => 'nmbp']);
        grantWorkspaceStepStoreAccess($caller, $node);
        $app = App::factory()->create([
            'name' => 'hauser',
        ]);
        create_workspace_step_store_instance(app: $app, node: $node, instanceName: 'nmbp');

        $response = $this->call(
            'POST',
            '/api/workspaces/steps/setup',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => WORKSPACE_STEP_STORE_CALLER_WG_IP,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'instance' => 'hauser.nmbp',
                'command' => $command,
            ], JSON_THROW_ON_ERROR),
        );

        $response
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'command')
            ->assertJsonPath('error.meta.reason', 'parent_env_inheritance_forbidden');

        expect(WorkspaceStep::query()->count())->toBe(0);
    })->with([
        'quoted path' => 'cp "$ORBIT_APP_PATH/.env" .env',
        'quoted variable' => 'cp "$ORBIT_APP_PATH"/.env .env',
        'quoted braced variable' => 'cp "${ORBIT_APP_PATH}"/.env .env',
    ]);

    it('stores instance-specific setup steps for dotted selectors without exposing them to sibling instances', function (): void {
        $caller = createWorkspaceStepStoreCallerNode();
        $nmbpNode = createTestAppHostNode(['name' => 'NMBP', 'tld' => 'nmbp']);
        $developmentNode = createTestAppHostNode(['name' => 'dev-host', 'tld' => 'dev']);
        grantWorkspaceStepStoreAccess($caller, $nmbpNode);
        grantWorkspaceStepStoreAccess($caller, $developmentNode, ['workspace:write', 'workspace:read']);
        $app = App::factory()->create([
            'name' => 'hauser',
        ]);
        $nmbpInstance = create_workspace_step_store_instance(
            app: $app,
            node: $nmbpNode,
            instanceName: 'nmbp',
        );
        create_workspace_step_store_instance(
            app: $app,
            node: $developmentNode,
            instanceName: 'development',
        );

        $response = $this->call(
            'POST',
            '/api/workspaces/steps/setup',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => WORKSPACE_STEP_STORE_CALLER_WG_IP,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'instance' => 'hauser.nmbp',
                'command' => 'composer install',
                'timeout' => 600,
            ], JSON_THROW_ON_ERROR),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.result.action', 'added')
            ->assertJsonPath('success.data.step.app', 'hauser')
            ->assertJsonPath('success.data.step.instance', 'nmbp');

        $stored = WorkspaceStep::query()->sole();

        expect($stored->instance_id)
            ->toBe($nmbpInstance->id)
            ->and(WorkspaceStep::query()->whereNull('instance_id')->count())
            ->toBe(0);

        $developmentList = $this->call(
            'GET',
            '/api/workspaces/steps/setup?instance=hauser.development',
            [],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_STEP_STORE_CALLER_WG_IP],
        );

        $developmentList
            ->assertOk()
            ->assertJsonPath('success.data.steps', []);
    });

    it('stores instance-specific policy through an app-instance selector on the selected node', function (): void {
        $caller = createWorkspaceStepStoreCallerNode();
        $localNode = createTestAppHostNode(['name' => 'NMBP', 'tld' => 'nmbp']);
        grantWorkspaceStepStoreAccess($caller, $localNode);
        $app = App::factory()->create([
            'name' => 'happie',
        ]);
        $instance = create_workspace_step_store_instance(
            app: $app,
            node: $localNode,
            instanceName: 'nmbp',
        );

        $response = $this->call(
            'POST',
            '/api/workspaces/steps/setup',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => WORKSPACE_STEP_STORE_CALLER_WG_IP,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'instance' => 'happie.nmbp',
                'command' => 'composer install',
                'timeout' => 600,
            ], JSON_THROW_ON_ERROR),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.result.action', 'added')
            ->assertJsonPath('success.data.step.app', 'happie');

        expect(WorkspaceStep::query()->sole()->instance_id)->toBe($instance->id);
    });

    it('rejects app-only selectors for workspace step writes', function (): void {
        $caller = createWorkspaceStepStoreCallerNode();
        $node = createTestAppHostNode();
        grantWorkspaceStepStoreAccess($caller, $node);
        $app = App::factory()->create(['name' => 'docs']);
        Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(node_id: $node->id, node: $node->name),
        ]);

        $response = $this->call(
            'POST',
            '/api/workspaces/steps/setup',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => WORKSPACE_STEP_STORE_CALLER_WG_IP,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'instance' => 'docs',
                'command' => 'composer install',
                'timeout' => 600,
            ], JSON_THROW_ON_ERROR),
        );

        $response
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'instance')
            ->assertJsonPath('error.meta.reason', 'instance_required');

        expect(WorkspaceStep::query()->count())->toBe(0);
    });

    it('rejects callers without workspace step write permission', function (): void {
        createWorkspaceStepStoreCallerNode(role: 'app-dev');
        $node = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs']);
        Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(node_id: $node->id, node: $node->name),
        ]);

        $response = $this->call(
            'POST',
            '/api/workspaces/steps/setup',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => WORKSPACE_STEP_STORE_CALLER_WG_IP,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['instance' => 'docs', 'command' => 'composer install'], JSON_THROW_ON_ERROR),
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'workspace:write');
    });

    it('validates bad timeout and unknown anchors for dotted selectors', function (): void {
        $caller = createWorkspaceStepStoreCallerNode(role: 'gateway');
        $localNode = createTestAppHostNode(['name' => 'NMBP', 'tld' => 'nmbp']);
        grantWorkspaceStepStoreAccess($caller, $localNode);
        $app = App::factory()->create([
            'name' => 'docs',
        ]);
        $instance = create_workspace_step_store_instance(
            app: $app,
            node: $localNode,
            instanceName: 'nmbp',
        );
        WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'phase' => WorkspaceLifecyclePhase::Teardown,
        ]);

        $timeout = $this->call(
            'POST',
            '/api/workspaces/steps/setup',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => WORKSPACE_STEP_STORE_CALLER_WG_IP,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'instance' => 'docs.nmbp',
                'command' => 'composer install',
                'timeout' => 0,
            ], JSON_THROW_ON_ERROR),
        );
        $anchor = $this->call(
            'POST',
            '/api/workspaces/steps/setup',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => $caller->wireguard_address,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'instance' => 'docs.nmbp',
                'command' => 'composer install',
                'before' => 999,
            ], JSON_THROW_ON_ERROR),
        );

        $timeout->assertStatus(400)
            ->assertJsonPath('error.meta.field', 'timeout');
        $anchor
            ->assertNotFound()
            ->assertJsonPath('error.code', 'workspace.step_not_found')
            ->assertJsonPath(
                'error.message',
                "Referenced insertion step '999' not found for app 'docs' in phase 'setup'.",
            );
    });

    it('rejects rows from another app instance as anchors', function (): void {
        $caller = createWorkspaceStepStoreCallerNode();
        $canonicalNode = createTestAppHostNode(['name' => 'beast', 'tld' => 'test']);
        $localNode = createTestAppHostNode(['name' => 'NMBP', 'tld' => 'nmbp']);
        grantWorkspaceStepStoreAccess($caller, $localNode);
        $app = App::factory()->create([
            'name' => 'hauser',
        ]);
        create_workspace_step_store_instance(app: $app, node: $localNode, instanceName: 'nmbp');
        $other = create_workspace_step_store_instance(
            app: $app,
            node: $canonicalNode,
            instanceName: 'development',
        );
        $foreignStep = WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $other->id,
            'phase' => WorkspaceLifecyclePhase::Setup,
            'command' => 'development composer install',
        ]);

        $response = $this->call(
            'POST',
            '/api/workspaces/steps/setup',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => WORKSPACE_STEP_STORE_CALLER_WG_IP,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'instance' => 'hauser.nmbp',
                'command' => 'composer install',
                'before' => $foreignStep->id,
            ], JSON_THROW_ON_ERROR),
        );

        $response
            ->assertNotFound()
            ->assertJsonPath('error.code', 'workspace.step_not_found')
            ->assertJsonPath(
                'error.message',
                "Referenced insertion step '{$foreignStep->id}' not found for app 'hauser' in phase 'setup'.",
            );

        expect(WorkspaceStep::query()->count())
            ->toBe(1)
            ->and(WorkspaceStep::query()->sole()->instance_id)
            ->toBe($other->id);
    });
});
