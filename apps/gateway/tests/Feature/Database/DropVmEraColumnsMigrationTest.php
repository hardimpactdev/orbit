<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/** The four VM-era columns that the migration drops, by table. */
const VmEraColumns = ['nodes' => ['image_refresh_hold'], 'tasks' => ['topology', 'idle_saved_commit', 'idle_saved_at']];

function vmEraMigration(): object
{
    return require database_path('migrations/2027_03_01_000000_drop_vm_era_columns_and_task_vms_setting.php');
}

function vmEraNode(string $name, ?string $settings, array $columns = []): int
{
    return DB::table('nodes')->insertGetId([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.7', 'user' => 'orbit', 'settings' => $settings,
        'created_at' => now(), 'updated_at' => now(), ...$columns,
    ]);
}

it('removes the task_vms settings key and drops the four columns, and its rollback adds them back empty', function (): void {
    $migration = vmEraMigration();
    $migration->down();
    $host = vmEraNode('beast', json_encode(['task_vms' => ['cidr' => '10.252.7.0/24', 'project' => 'orbit-tasks'], 't3' => ['url' => 'https://t3.orbit']]),
        ['image_refresh_hold' => true]);
    $only = vmEraNode('shark', json_encode(['task_vms' => ['cidr' => '10.252.8.0/24']]));
    $plain = vmEraNode('apps', json_encode(['apps' => ['path' => '/home/orbit/apps']]));
    $empty = vmEraNode('usexit', null);
    $task = DB::table('tasks')->insertGetId(['title' => 'Group', 'brief' => 'Brief', 'status' => 'running', 'topology' => json_encode(['app-dev']),
        'idle_saved_commit' => str_repeat('a', 40), 'idle_saved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $migration->up();

    $settings = static fn (int $id): ?array => json_decode((string) DB::table('nodes')->where('id', $id)->value('settings'), true);
    expect($settings($host))->toBe(['t3' => ['url' => 'https://t3.orbit']])
        ->and($settings($only))->toBe([])
        ->and($settings($plain))->toBe(['apps' => ['path' => '/home/orbit/apps']])
        ->and(DB::table('nodes')->where('id', $empty)->value('settings'))->toBeNull()
        ->and(DB::table('tasks')->where('id', $task)->value('title'))->toBe('Group');
    foreach (VmEraColumns as $table => $columns) {
        foreach ($columns as $column) {
            expect(Schema::hasColumn($table, $column))->toBeFalse();
        }
    }

    $migration->down();

    expect(DB::table('nodes')->pluck('image_refresh_hold')->map(static fn (mixed $hold): bool => (bool) $hold)->unique()->all())->toBe([false])
        ->and(DB::table('tasks')->where('id', $task)->first(['topology', 'idle_saved_commit', 'idle_saved_at']))
        ->toEqual((object) ['topology' => null, 'idle_saved_commit' => null, 'idle_saved_at' => null])
        ->and($settings($host))->toBe(['t3' => ['url' => 'https://t3.orbit']]);

    $migration->up();
});

it('leaves a Node whose settings are not JSON untouched', function (): void {
    $migration = vmEraMigration();
    $migration->down();
    $broken = vmEraNode('broken', 'not json');

    $migration->up();

    expect(DB::table('nodes')->where('id', $broken)->value('settings'))->toBe('not json');
});

it('leaves no index, trigger or view that names a dropped column', function (): void {
    $names = array_merge(...array_values(VmEraColumns));
    $schema = DB::table('sqlite_master')->whereIn('type', ['index', 'trigger', 'view'])->whereNotNull('sql')->pluck('sql')->all();

    expect(array_filter($schema, static fn (string $sql): bool => array_any($names, static fn (string $name): bool => str_contains($sql, $name))))->toBe([]);
});
