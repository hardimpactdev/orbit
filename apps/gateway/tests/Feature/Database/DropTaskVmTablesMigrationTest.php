<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * The three task VM tables as every migration before this one left them, read from a Gateway database migrated to that point:
 * each column with its type, nullability, default and primary key, each foreign key, and each index with its WHERE.
 */
const TaskVmTablesAtBase = [
    'task_vms' => [
        'columns' => [
            'id INTEGER not null primary key', 'group_id INTEGER', 'host_node_id INTEGER', 'node_id INTEGER', 'provider varchar not null',
            'name varchar not null', 'state varchar not null', 'address varchar', 'wireguard_ip varchar not null', 'pi_token TEXT', 'error_code varchar',
            'error_message TEXT', 'ready_at datetime', 'destroyed_at datetime', 'created_at datetime', 'updated_at datetime', 'claimed_at datetime',
            'warmed_at datetime', 'image_fingerprint varchar', "size varchar not null default 'normal'", 'topology TEXT', 'topology_state varchar',
            'topology_run varchar', 'topology_started_at datetime', 'topology_error TEXT', 'publish_commit varchar', 'publish_branch varchar',
            'publish_state varchar', 'publish_error TEXT', 'publish_queued_at datetime', 'publish_started_at datetime', 'recipe_identity varchar',
            'publish_error_code varchar', 'stop_confirmed_at datetime', 'image_build_id INTEGER', "image_fallback tinyint(1) not null default '0'",
            'slot_project_id INTEGER', 'slot_number INTEGER', 'wireguard_private_key TEXT', 'ssh_host_private_key TEXT', 'agent_secret TEXT',
            "capacity_pool varchar not null default 'task'", "runtime varchar not null default 'vm'",
        ],
        'foreign_keys' => ['node_id -> nodes.id on delete SET NULL', 'host_node_id -> nodes.id on delete NO ACTION', 'group_id -> tasks.id on delete NO ACTION'],
        'indexes' => [
            'task_vms_capacity_pool_index' => '(capacity_pool)',
            'task_vms_group_id_index' => '(group_id)',
            'task_vms_host_node_id_state_index' => '(host_node_id, state)',
            'task_vms_host_slot_unique' => "unique (host_node_id, slot_number) where slot_number IS NOT NULL AND state != 'destroyed'",
            'task_vms_live_group_id_unique' => "unique (group_id) where state != 'destroyed'",
            'task_vms_live_wireguard_ip_unique' => "unique (wireguard_ip) where state != 'destroyed'",
            'task_vms_name_unique' => "unique (name) where state != 'destroyed'",
            'task_vms_node_id_unique' => 'unique (node_id)',
            'task_vms_state_index' => '(state)',
        ],
    ],
    'task_vm_image_builds' => [
        'columns' => [
            'id INTEGER not null primary key', 'host_node_id INTEGER not null', 'source_alias varchar', 'source_fingerprint varchar',
            "from_stock tinyint(1) not null default '0'", 'builder_vm_name varchar', 'image_fingerprint varchar', 'current_step varchar',
            'current_program varchar', "state varchar not null default 'running'", "steps TEXT not null default '[]'", 'created_at datetime',
            'updated_at datetime', 'submission_pending_at varchar', 'cleanup_started_at datetime', "attempt INTEGER not null default '1'",
            'recipe_identity varchar', 'recipe_json TEXT', 'recipe_generation INTEGER', "images TEXT not null default '{}'", 'promoted_at datetime',
            'fleet_convergence TEXT', "runtime varchar not null default 'vm'",
        ],
        'foreign_keys' => ['host_node_id -> nodes.id on delete RESTRICT'],
        'indexes' => [
            'task_vm_image_builds_host_node_id_recipe_identity_index' => '(host_node_id, recipe_identity)',
            'task_vm_image_builds_running_host_unique' => "unique (host_node_id) where state = 'running'",
        ],
    ],
    'task_vm_image_recipe' => [
        'columns' => ['id INTEGER not null primary key', 'identity varchar', 'recipe_json TEXT', "generation INTEGER not null default '0'"],
        'foreign_keys' => [],
        'indexes' => [],
    ],
];

function taskVmTablesMigration(): object
{
    return require database_path('migrations/2027_03_03_000000_drop_task_vm_tables.php');
}

/**
 * A task VM table's schema in the shape of {@see TaskVmTablesAtBase}.
 *
 * @return array{columns: list<string>, foreign_keys: list<string>, indexes: array<string, string>}
 */
function taskVmTableSchema(string $table): array
{
    $columns = array_map(static fn (object $column): string => $column->name.' '.$column->type.($column->notnull ? ' not null' : '')
        .($column->dflt_value === null ? '' : ' default '.$column->dflt_value).($column->pk ? ' primary key' : ''), DB::select("PRAGMA table_info(\"{$table}\")"));
    $foreignKeys = array_map(static fn (object $key): string => "{$key->from} -> {$key->table}.{$key->to} on delete {$key->on_delete}",
        DB::select("PRAGMA foreign_key_list(\"{$table}\")"));
    $indexes = [];
    foreach (DB::select("PRAGMA index_list(\"{$table}\")") as $index) {
        if ($index->origin === 'pk') {
            continue;
        }
        $columnsOf = array_column(DB::select("PRAGMA index_info(\"{$index->name}\")"), 'name');
        $sql = (string) DB::table('sqlite_master')->where('name', $index->name)->value('sql');
        $where = preg_match('/WHERE (.*)$/si', $sql, $match) === 1 ? ' where '.trim($match[1]) : '';
        $indexes[$index->name] = ($index->unique ? 'unique ' : '').'('.implode(', ', $columnsOf).')'.$where;
    }
    ksort($indexes);

    return ['columns' => $columns, 'foreign_keys' => $foreignKeys, 'indexes' => $indexes];
}

it('drops the three task VM tables, and its rollback creates them again empty with the schema they had', function (): void {
    $migration = taskVmTablesMigration();

    // Every migration ran here, this one included.
    foreach (array_keys(TaskVmTablesAtBase) as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    $migration->down();

    foreach (TaskVmTablesAtBase as $table => $schema) {
        expect(taskVmTableSchema($table))->toBe($schema)->and(DB::table($table)->count())->toBe(0);
    }

    $migration->up();
    $migration->up();

    foreach (array_keys(TaskVmTablesAtBase) as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
});

it('leaves no table with a foreign key to a task VM table, and no index, trigger or view that names one', function (): void {
    $dropped = array_keys(TaskVmTablesAtBase);
    $tables = array_column(Schema::getTables(), 'name');
    $references = array_merge(...array_map(static fn (string $table): array => array_column(Schema::getForeignKeys($table), 'foreign_table'), $tables));
    $schema = DB::table('sqlite_master')->whereIn('type', ['index', 'trigger', 'view'])->whereNotNull('sql')->pluck('sql')->all();

    expect(array_intersect($tables, $dropped))->toBe([])
        ->and(array_intersect($references, $dropped))->toBe([])
        ->and(array_filter($schema, static fn (string $sql): bool => array_any($dropped, static fn (string $table): bool => str_contains($sql, $table))))->toBe([]);
});
