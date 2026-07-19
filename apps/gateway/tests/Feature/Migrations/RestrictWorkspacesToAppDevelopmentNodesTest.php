<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function run_restrict_workspaces_to_app_development_nodes_migration(): void
{
    /** @var mixed $migration */
    $migration = require
        database_path(
            'migrations/2026_07_19_000000_restrict_workspaces_to_app_development_nodes.php',
        );

    if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
        throw new \RuntimeException('Workspace role boundary migration must expose up().');
    }

    $migration->up();
}

it('canonicalizes setup state and removes workspace grants involving production nodes', function (): void {
    $consumer = Node::factory()->create();
    $productionConsumer = Node::factory()->create();
    $developmentNode = Node::factory()->create();
    $productionNode = Node::factory()->create();
    NodeRoleAssignment::factory()->create([
        'node_id' => $productionConsumer->id,
        'role' => 'app-prod',
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $developmentNode->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $productionNode->id,
        'role' => 'app-prod',
        'status' => 'active',
    ]);

    $app = App::factory()->for($developmentNode, 'node')->create();
    $workspace = Workspace::factory()->for($app, 'app')->create();
    DB::table('workspaces')
        ->where('id', $workspace->id)
        ->update(['lifecycle_status' => 'setting_up']);

    $developmentGrant = NodeAccess::query()->create([
        'consumer_node_id' => $consumer->id,
        'serving_node_id' => $developmentNode->id,
        'permissions' => ['app:read', 'workspace:read'],
        'custom_permissions' => ['workspace:read'],
    ]);
    $productionGrant = NodeAccess::query()->create([
        'consumer_node_id' => $consumer->id,
        'serving_node_id' => $productionNode->id,
        'permissions' => ['app:read', '*', 'workspace:read', 'workspace:setup'],
        'custom_permissions' => ['*', 'workspace:read'],
    ]);
    $productionConsumerGrant = NodeAccess::query()->create([
        'consumer_node_id' => $productionConsumer->id,
        'serving_node_id' => $developmentNode->id,
        'permissions' => ['app:read', '*', 'workspace:read', 'workspace:setup'],
        'custom_permissions' => ['*', 'workspace:read'],
    ]);

    run_restrict_workspaces_to_app_development_nodes_migration();

    expect(DB::table('workspaces')->where('id', $workspace->id)->value('lifecycle_status'))
        ->toBe('setup-pending')
        ->and($productionGrant->fresh()?->permissions)
        ->toContain('app:read', 'deploy:run', 'node:read')
        ->not->toContain(
            '*',
            'workspace:*',
            'workspace:read',
            'workspace:setup',
        )->and($productionGrant->fresh()?->custom_permissions)->toContain('app:read', 'deploy:run', 'node:read')
        ->not->toContain(
            '*',
            'workspace:*',
            'workspace:read',
            'workspace:setup',
        )->and($productionConsumerGrant->fresh()?->permissions)->toContain('app:read', 'deploy:run', 'node:read')
        ->not->toContain(
            '*',
            'workspace:*',
            'workspace:read',
            'workspace:setup',
        )->and($productionConsumerGrant->fresh()?->custom_permissions)->toContain('app:read', 'deploy:run', 'node:read')
        ->not->toContain(
            '*',
            'workspace:*',
            'workspace:read',
            'workspace:setup',
        )->and($developmentGrant->fresh()?->permissions)->toBe([
            'app:read',
            'workspace:read',
        ])->and($developmentGrant->fresh()?->custom_permissions)->toBe(['workspace:read']);
});

it('fails closed when persisted workspace ownership points at app production', function (): void {
    $productionNode = Node::factory()->appProd()->create(['name' => 'app-prod-1']);
    $app = App::factory()->for($productionNode, 'node')->create(['name' => 'docs']);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'production',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $productionNode->id,
            node: $productionNode->name,
            path: '/srv/docs',
        ),
    ]);
    $workspace = Workspace::factory()->for($app)->create([
        'name' => 'legacy-workspace',
        'app_instance_id' => $instance->id,
    ]);

    expect(fn () => run_restrict_workspaces_to_app_development_nodes_migration())
        ->toThrow(RuntimeException::class, 'app-prod-1');

    expect($workspace->fresh())->not->toBeNull();
});

it('fails closed when name-only workspace ownership points at app production', function (): void {
    $productionNode = Node::factory()->appProd()->create(['name' => 'app-prod-name-only']);
    $app = App::factory()->for($productionNode, 'node')->create(['name' => 'docs']);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'production',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node: $productionNode->name,
            path: '/srv/docs',
        ),
    ]);
    $workspace = Workspace::factory()->for($app)->create([
        'name' => 'legacy-name-only-workspace',
        'app_instance_id' => $instance->id,
    ]);

    expect(fn () => run_restrict_workspaces_to_app_development_nodes_migration())
        ->toThrow(RuntimeException::class, 'app-prod-name-only');

    expect($workspace->fresh())->not->toBeNull();
});
