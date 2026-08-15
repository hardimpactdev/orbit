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
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const WORKSPACE_STEP_DELETE_CALLER_WG_IP = '10.6.0.97';

function createWorkspaceStepDeleteCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'step-delete-caller',
        'host' => WORKSPACE_STEP_DELETE_CALLER_WG_IP,
        'wireguard_address' => WORKSPACE_STEP_DELETE_CALLER_WG_IP,
    ], $overrides);

    return match ($role) {
        'app-dev' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

function grantWorkspaceStepDeleteAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['workspace:write'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function createWorkspaceStepDeleteInstance(App $app, Node $node, string $instanceName): Instance
{
    return Instance::factory()->create([
        'app_id' => $app->id,
        'name' => $instanceName,
        'driver' => InstanceDriver::Orbit,
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            path: $app->path,
            domain: "{$app->name}.{$instanceName}",
        ),
    ]);
}

describe('WorkspaceStepDeleteController', function (): void {
    it('does not delete another app instance step through a dotted selector', function (): void {
        $caller = createWorkspaceStepDeleteCallerNode();
        $canonicalNode = createTestAppHostNode(['name' => 'beast', 'tld' => 'test']);
        $localNode = createTestAppHostNode(['name' => 'NMBP', 'tld' => 'nmbp']);
        grantWorkspaceStepDeleteAccess($caller, $localNode);
        $app = App::factory()->create([
            'name' => 'happie',
            'node_id' => $canonicalNode->id,
            'path' => '/home/nckrtl/apps/happie',
        ]);
        createWorkspaceStepDeleteInstance($app, $localNode, 'nmbp');
        $other = createWorkspaceStepDeleteInstance($app, $canonicalNode, 'development');
        $foreignStep = WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $other->id,
            'phase' => WorkspaceLifecyclePhase::Setup,
            'command' => 'composer install',
        ]);

        $response = $this->call(
            'DELETE',
            "/api/workspaces/steps/setup/{$foreignStep->id}",
            [
                'instance' => 'happie.nmbp',
                'destructive_consent' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_STEP_DELETE_CALLER_WG_IP],
        );

        $response
            ->assertNotFound()
            ->assertJsonPath('error.code', 'workspace.step_not_found');

        expect(WorkspaceStep::query()->whereKey($foreignStep->id)->exists())->toBeTrue();
    });

    it('deletes only instance-specific steps for the selected app instance', function (): void {
        $caller = createWorkspaceStepDeleteCallerNode();
        $canonicalNode = createTestAppHostNode(['name' => 'beast', 'tld' => 'test']);
        $nmbpNode = createTestAppHostNode(['name' => 'NMBP', 'tld' => 'nmbp']);
        $developmentNode = createTestAppHostNode(['name' => 'dev-host', 'tld' => 'dev']);
        grantWorkspaceStepDeleteAccess($caller, $nmbpNode);
        grantWorkspaceStepDeleteAccess($caller, $developmentNode);
        $app = App::factory()->create([
            'name' => 'hauser',
            'node_id' => $canonicalNode->id,
            'path' => '/home/nckrtl/apps/hauser',
        ]);
        $nmbpInstance = createWorkspaceStepDeleteInstance($app, $nmbpNode, 'nmbp');
        createWorkspaceStepDeleteInstance($app, $developmentNode, 'development');
        $removed = WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $nmbpInstance->id,
            'phase' => WorkspaceLifecyclePhase::Setup,
            'command' => 'composer install',
        ]);
        WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $nmbpInstance->id,
            'phase' => WorkspaceLifecyclePhase::Setup,
            'sort_order' => 2,
            'command' => 'npm install',
        ]);

        $response = $this->call(
            'DELETE',
            "/api/workspaces/steps/setup/{$removed->id}",
            [
                'instance' => 'hauser.nmbp',
                'destructive_consent' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_STEP_DELETE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.meta.remaining_step_count', 1);

        $missingForSibling = $this->call(
            'DELETE',
            "/api/workspaces/steps/setup/{$removed->id}",
            [
                'instance' => 'hauser.development',
                'destructive_consent' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_STEP_DELETE_CALLER_WG_IP],
        );

        $missingForSibling
            ->assertNotFound()
            ->assertJsonPath('error.code', 'workspace.step_not_found');
    });

    it('rejects app-only selectors for workspace step deletes', function (): void {
        $caller = createWorkspaceStepDeleteCallerNode();
        $node = createTestAppHostNode();
        grantWorkspaceStepDeleteAccess($caller, $node);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $step = WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'phase' => WorkspaceLifecyclePhase::Setup,
        ]);

        $response = $this->call(
            'DELETE',
            "/api/workspaces/steps/setup/{$step->id}",
            [
                'instance' => 'docs',
                'destructive_consent' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_STEP_DELETE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'instance')
            ->assertJsonPath('error.meta.reason', 'instance_required');

        expect(WorkspaceStep::query()->whereKey($step->id)->exists())->toBeTrue();
    });

    it('deletes instance-owned workspace steps for authorized callers and compacts order', function (): void {
        $caller = createWorkspaceStepDeleteCallerNode();
        $canonicalNode = createTestAppHostNode(['name' => 'beast', 'tld' => 'test']);
        $localNode = createTestAppHostNode(['name' => 'NMBP', 'tld' => 'nmbp']);
        grantWorkspaceStepDeleteAccess($caller, $localNode);
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $canonicalNode->id,
            'path' => '/home/nckrtl/apps/docs',
        ]);
        $instance = createWorkspaceStepDeleteInstance($app, $localNode, 'nmbp');
        WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'phase' => WorkspaceLifecyclePhase::Setup,
            'sort_order' => 1,
        ]);
        $removed = WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'phase' => WorkspaceLifecyclePhase::Setup,
            'sort_order' => 2,
            'command' => 'npm install',
        ]);
        WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'phase' => WorkspaceLifecyclePhase::Setup,
            'sort_order' => 3,
        ]);

        $response = $this->call(
            'DELETE',
            "/api/workspaces/steps/setup/{$removed->id}",
            [
                'instance' => 'docs.nmbp',
                'destructive_consent' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_STEP_DELETE_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.result.action', 'removed')
            ->assertJsonPath('success.data.step.id', $removed->id)
            ->assertJsonPath('success.data.step.command', 'npm install')
            ->assertJsonPath('success.meta.remaining_step_count', 2);

        expect(WorkspaceStep::query()->whereKey($removed->id)->exists())
            ->toBeFalse()
            ->and(
                WorkspaceStep::query()
                    ->where('instance_id', $instance->id)
                    ->where('phase', WorkspaceLifecyclePhase::Setup)
                    ->orderBy('sort_order')
                    ->pluck('sort_order')
                    ->all(),
            )
            ->toBe([1, 2]);
    });

    it('logs destructive activity for successful workspace step deletion', function (): void {
        $caller = createWorkspaceStepDeleteCallerNode();
        $canonicalNode = createTestAppHostNode(['name' => 'beast', 'tld' => 'test']);
        $localNode = createTestAppHostNode(['name' => 'NMBP', 'tld' => 'nmbp']);
        grantWorkspaceStepDeleteAccess($caller, $localNode);
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $canonicalNode->id,
            'path' => '/home/nckrtl/apps/docs',
        ]);
        $instance = createWorkspaceStepDeleteInstance($app, $localNode, 'nmbp');
        $removed = WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'phase' => WorkspaceLifecyclePhase::Teardown,
            'sort_order' => 1,
        ]);

        $response = $this->call(
            'DELETE',
            "/api/workspaces/steps/teardown/{$removed->id}",
            [
                'instance' => 'docs.nmbp',
                'destructive_consent' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_STEP_DELETE_CALLER_WG_IP],
        );

        $response->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->event)->toBe('api:DELETE /workspaces/steps/{phase}/{step}');
        expect($entry->subject_type)->toBe(WorkspaceStep::class);
        expect($entry->subject_id)->toBe($removed->id);
        expect($entry->properties->get('type'))->toBe('destructive');
    });

    it('requires destructive consent before deleting workspace steps', function (): void {
        $caller = createWorkspaceStepDeleteCallerNode();
        $canonicalNode = createTestAppHostNode(['name' => 'beast', 'tld' => 'test']);
        $localNode = createTestAppHostNode(['name' => 'NMBP', 'tld' => 'nmbp']);
        grantWorkspaceStepDeleteAccess($caller, $localNode);
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $canonicalNode->id,
            'path' => '/home/nckrtl/apps/docs',
        ]);
        $instance = createWorkspaceStepDeleteInstance($app, $localNode, 'nmbp');
        $step = WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'phase' => WorkspaceLifecyclePhase::Setup,
        ]);

        $response = $this->call(
            'DELETE',
            "/api/workspaces/steps/setup/{$step->id}",
            ['instance' => 'docs.nmbp'],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_STEP_DELETE_CALLER_WG_IP],
        );

        $response
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'force');

        expect(WorkspaceStep::query()->whereKey($step->id)->exists())->toBeTrue();
    });

    it('rejects callers without workspace step write permission', function (): void {
        createWorkspaceStepDeleteCallerNode(role: 'app-dev');
        $node = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(node_id: $node->id, node: $node->name),
        ]);

        $response = $this->call(
            'DELETE',
            '/api/workspaces/steps/setup/12',
            ['instance' => 'docs'],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_STEP_DELETE_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'workspace:write');
    });

    it('returns phase-scoped step-not-found errors for instance-owned rows', function (): void {
        $caller = createWorkspaceStepDeleteCallerNode(role: 'gateway');
        $canonicalNode = createTestAppHostNode(['name' => 'beast', 'tld' => 'test']);
        $localNode = createTestAppHostNode(['name' => 'NMBP', 'tld' => 'nmbp']);
        grantWorkspaceStepDeleteAccess($caller, $localNode);
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $canonicalNode->id,
            'path' => '/home/nckrtl/apps/docs',
        ]);
        $instance = createWorkspaceStepDeleteInstance($app, $localNode, 'nmbp');
        $step = WorkspaceStep::factory()->create([
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'phase' => WorkspaceLifecyclePhase::Teardown,
        ]);

        $response = $this->call(
            'DELETE',
            "/api/workspaces/steps/setup/{$step->id}",
            [
                'instance' => 'docs.nmbp',
                'destructive_consent' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_STEP_DELETE_CALLER_WG_IP],
        );

        $response
            ->assertNotFound()
            ->assertJsonPath('error.code', 'workspace.step_not_found')
            ->assertJsonPath('error.meta.phase', 'setup');
    });
});
