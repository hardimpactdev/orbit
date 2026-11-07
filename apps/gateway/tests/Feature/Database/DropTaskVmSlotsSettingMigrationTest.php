<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/** The task_vms indexes the migration owns, by name. */
function slotMigrationIndexes(): array
{
    return DB::table('sqlite_master')->where('tbl_name', 'task_vms')->where('type', 'index')
        ->whereIn('name', ['task_vms_name_unique', 'task_vms_host_slot_unique'])->pluck('sql', 'name')->all();
}

it('runs again on a Gateway where a run stopped half way and the name index was already gone', function (): void {
    // The live state after release 1770: the settings and the column were done, then DROP INDEX failed on a missing index.
    DB::statement('DROP INDEX IF EXISTS task_vms_name_unique');
    DB::statement('DROP INDEX IF EXISTS task_vms_host_slot_unique');
    $migration = require database_path('migrations/2027_01_25_000000_drop_task_vm_slots_setting.php');

    $migration->up();
    $migration->up();

    $indexes = slotMigrationIndexes();
    expect(Schema::hasColumn('task_vms', 'slot_digest'))->toBeFalse()
        ->and($indexes['task_vms_name_unique'] ?? '')->toContain("WHERE state != 'destroyed'")
        ->and($indexes['task_vms_host_slot_unique'] ?? '')->toContain('slot_number IS NOT NULL');
});

it('hands the ready slots of the old pool to the destroy, and leaves group VMs and slot rows alone', function (): void {
    $host = DB::table('nodes')->insertGetId(['name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit', 'created_at' => now(), 'updated_at' => now()]);
    $row = static fn (string $name, array $extra): int => DB::table('task_vms')->insertGetId([...['host_node_id' => $host, 'provider' => 'incus', 'size' => 'normal',
        'name' => $name, 'state' => 'parked', 'wireguard_ip' => '10.44.0.'.random_int(130, 250), 'created_at' => now(), 'updated_at' => now()], ...$extra]);
    $ready = $row('tvm-875', ['slot_project_id' => 66]);
    $slot = $row('beast-tvm-1', ['slot_project_id' => 46, 'slot_number' => 1, 'state' => 'provisioning']);
    $grouped = $row('tvm-610', []);

    (require database_path('migrations/2027_01_26_000000_retire_ready_task_vm_slots.php'))->up();

    expect(DB::table('task_vms')->where('id', $ready)->value('state'))->toBe('destroying')
        ->and(DB::table('task_vms')->where('id', $slot)->value('state'))->toBe('provisioning')
        ->and(DB::table('task_vms')->where('id', $grouped)->value('state'))->toBe('parked');
});
