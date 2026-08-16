<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Historical 2026_07_19 migration joins app_instances. After the 2026-08-05
 * cutover the final schema has instances; re-applying the immutable historical
 * file is a no-op when app_instances is gone (effects already applied during
 * the sequential migrate path while the table still existed).
 */
function run_restrict_workspaces_to_app_development_nodes_migration(): void
{
    if (! Schema::hasTable('app_instances')) {
        return;
    }

    /** @var mixed $migration */
    $migration = require
        database_path(
            'migrations/2026_07_19_000000_restrict_workspaces_to_app_development_nodes.php',
        );

    if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
        throw new RuntimeException('Workspace role boundary migration must expose up().');
    }

    $migration->up();
}

it('is a no-op when re-run after the app_instances to instances cutover', function (): void {
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

    $app = App::factory()->create();
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

    expect(Schema::hasTable('app_instances'))->toBeFalse();

    run_restrict_workspaces_to_app_development_nodes_migration();

    // Historical re-application is skipped on the final schema; state is unchanged.
    expect(DB::table('workspaces')->where('id', $workspace->id)->value('lifecycle_status'))
        ->toBe('setting_up')
        ->and($productionGrant->fresh()?->permissions)
        ->toBe(['app:read', '*', 'workspace:read', 'workspace:setup'])
        ->and($developmentGrant->fresh()?->permissions)
        ->toBe(['app:read', 'workspace:read']);
});

it('does not re-check production ownership after cutover when app_instances is gone', function (): void {
    $productionNode = Node::factory()->appProd()->create(['name' => 'app-prod-1']);
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->for($app)->create([
        'name' => 'production',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $productionNode->id,
            node: $productionNode->name,
            path: '/srv/docs',
        ),
    ]);
    $workspace = Workspace::factory()->for($app)->create([
        'name' => 'legacy-workspace',
        'instance_id' => $instance->id,
    ]);

    expect(Schema::hasTable('app_instances'))->toBeFalse();

    // No-op: historical join target is gone; fail-closed proof lived on pre-cutover schema.
    expect(fn () => run_restrict_workspaces_to_app_development_nodes_migration())
        ->not
        ->toThrow(RuntimeException::class);

    expect($workspace->fresh())->not->toBeNull();
});

it('does not re-check name-only production ownership after cutover when app_instances is gone', function (): void {
    $productionNode = Node::factory()->appProd()->create(['name' => 'app-prod-name-only']);
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->for($app)->create([
        'name' => 'production',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node: $productionNode->name,
            path: '/srv/docs',
        ),
    ]);
    $workspace = Workspace::factory()->for($app)->create([
        'name' => 'legacy-name-only-workspace',
        'instance_id' => $instance->id,
    ]);

    expect(Schema::hasTable('app_instances'))->toBeFalse();

    expect(fn () => run_restrict_workspaces_to_app_development_nodes_migration())
        ->not
        ->toThrow(RuntimeException::class);

    expect($workspace->fresh())->not->toBeNull();
});
