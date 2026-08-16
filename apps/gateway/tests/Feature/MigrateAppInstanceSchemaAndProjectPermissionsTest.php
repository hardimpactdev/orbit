<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\Workspace;
use App\Services\Nodes\Access\NodePermissionNormalizer;
use App\Services\Nodes\Access\NodePermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Tables that own concrete Instance foreign keys after the vocabulary cutover.
 *
 * @return list<string>
 */
function instance_owned_tables(): array
{
    return [
        'instance_env_variables',
        'instance_runtime_mounts',
        'workspaces',
        'workspace_steps',
        'processes',
        'process_events',
        'schedules',
        'deploy_steps',
        'deployment_runs',
        'app_setup_steps',
        'app_setup_runs',
        'database_connection_targets',
    ];
}

it('leaves no app_instances table or app_instance_id columns after cutover', function (): void {
    expect(Schema::hasTable('instances'))
        ->toBeTrue()
        ->and(Schema::hasTable('app_instances'))
        ->toBeFalse()
        ->and(Schema::hasTable('instance_env_variables'))
        ->toBeTrue()
        ->and(Schema::hasTable('app_instance_env_variables'))
        ->toBeFalse()
        ->and(Schema::hasTable('instance_runtime_mounts'))
        ->toBeTrue()
        ->and(Schema::hasTable('app_instance_runtime_mounts'))
        ->toBeFalse();

    foreach (instance_owned_tables() as $table) {
        if (! Schema::hasTable($table)) {
            continue;
        }

        expect(Schema::hasColumn($table, 'instance_id'))
            ->toBeTrue("{$table} must expose instance_id")
            ->and(Schema::hasColumn($table, 'app_instance_id'))
            ->toBeFalse("{$table} must not retain app_instance_id");
    }
});

it('keeps apps and app_id logical storage while FK ownership points at instances', function (): void {
    $node = Node::factory()->create(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->create([
        'app_id' => $app->id,
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/orbit/apps/docs',
            document_root: 'public',
        ),
    ]);
    $workspace = Workspace::factory()->create([
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'name' => 'feature',
    ]);
    $schedule = Schedule::factory()->create([
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'name' => 'nightly',
    ]);

    expect(Schema::hasTable('apps'))
        ->toBeTrue()
        ->and(Schema::hasColumn('instances', 'app_id'))
        ->toBeTrue()
        ->and((int) DB::table('instances')->where('id', $instance->id)->value('app_id'))
        ->toBe($app->id)
        ->and((int) DB::table('workspaces')->where('id', $workspace->id)->value('instance_id'))
        ->toBe($instance->id)
        ->and((int) DB::table('schedules')->where('id', $schedule->id)->value('instance_id'))
        ->toBe($instance->id)
        ->and(DB::table('instances')->count())
        ->toBe(1)
        ->and(DB::table('workspaces')->count())
        ->toBe(1)
        ->and(DB::table('schedules')->where('instance_id', $instance->id)->count())
        ->toBe(1);

    $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('workspaces')"))
        ->map(fn (object $row): array => [
            'from' => $row->from,
            'table' => $row->table,
            'to' => $row->to,
        ])
        ->all();

    expect($foreignKeys)->toContain([
        'from' => 'instance_id',
        'table' => 'instances',
        'to' => 'id',
    ]);

    foreach (DB::select("PRAGMA index_list('processes')") as $index) {
        $columns = collect(DB::select('PRAGMA index_info('.$index->name.')'))
            ->pluck('name')
            ->all();

        expect($columns)->not->toContain('app_instance_id');
    }
});

it('rewrites pre-cutover project tokens only inside the one-way migration', function (): void {
    $consumer = Node::factory()->create(['name' => 'operator']);
    $serving = Node::factory()->create(['name' => 'prod-1']);
    $grantId = DB::table('node_access')->insertGetId([
        'consumer_node_id' => $consumer->id,
        'serving_node_id' => $serving->id,
        // Pre-cutover grant: seed multiple project:* tokens that the cutover must map.
        'permissions' => json_encode(
            ['project:read', 'project:write', 'project:list', 'instance:read', 'node:read'],
            JSON_THROW_ON_ERROR,
        ),
        'custom_permissions' => json_encode(
            ['project:new', 'project:remove', 'project:*'],
            JSON_THROW_ON_ERROR,
        ),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require
        database_path(
            'migrations/2026_08_05_120000_rename_app_instances_to_instances_and_project_permissions_to_app.php',
        );

    // Table renames are no-ops when already cut over; permission rewrite still applies.
    $migration->up();

    $grant = DB::table('node_access')->find($grantId);
    $permissions = json_decode($grant->permissions, true, flags: JSON_THROW_ON_ERROR);
    $custom = json_decode($grant->custom_permissions, true, flags: JSON_THROW_ON_ERROR);

    expect($permissions)
        ->toBe(['app:read', 'app:write', 'app:list', 'instance:read', 'node:read'])
        ->and($custom)
        ->toBe(['app:new', 'app:remove', 'app:*'])
        ->and(array_values(array_filter($permissions, fn (string $p): bool => str_starts_with($p, 'project:'))))
        ->toBeEmpty()
        ->and(array_values(array_filter($custom, fn (string $p): bool => str_starts_with($p, 'project:'))))
        ->toBeEmpty();

    $normalizer = app(NodePermissionNormalizer::class);
    $registry = app(NodePermissionRegistry::class);

    // Runtime has no rewrite layer: leftover project tokens fail closed as unknown.
    expect($registry->all())
        ->not
        ->toContain('project:read', 'project:write', 'project:*')
        ->toContain('app:read', 'app:write', 'solo:project:list')
        ->and(fn () => $normalizer->normalize(['project:read']))
        ->toThrow(InvalidArgumentException::class, 'Unknown permission [project:read].');

    // Normalizer may collapse implied app:list under app:read; residual project:* must stay empty.
    $normalized = $normalizer->normalize($permissions)->permissions;
    expect($normalized)
        ->toContain('app:read', 'app:write', 'instance:read', 'node:read')
        ->and(array_values(array_filter($normalized, fn (string $p): bool => str_starts_with($p, 'project:'))))
        ->toBeEmpty();
});

it('proves post-migration grants surface only registry-known tokens without a runtime filter', function (): void {
    $consumer = Node::factory()->create(['name' => 'caller']);
    $serving = Node::factory()->create(['name' => 'app-1']);
    $grantId = DB::table('node_access')->insertGetId([
        'consumer_node_id' => $consumer->id,
        'serving_node_id' => $serving->id,
        'permissions' => json_encode(['app:read', 'instance:read', 'node:read'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode(['instance:register'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $grant = DB::table('node_access')->find($grantId);
    $permissions = json_decode($grant->permissions, true, flags: JSON_THROW_ON_ERROR);
    $registry = app(NodePermissionRegistry::class);

    expect($permissions)
        ->toBe(['app:read', 'instance:read', 'node:read'])
        ->and(array_filter($permissions, fn (string $p): bool => str_starts_with($p, 'project:')))
        ->toBeEmpty()
        ->and(array_values(array_filter(
            $permissions,
            fn (string $permission): bool => ! $registry->isKnown($permission),
        )))
        ->toBeEmpty();
});
