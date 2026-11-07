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
