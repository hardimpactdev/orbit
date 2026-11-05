<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The task VM slot concept is gone. This drops what 2027_01_15_000000_add_task_vm_slots added.
        // It changes no row: a leftover slot row stays as it is.
        if (Schema::hasIndex('task_vms', 'task_vms_host_slot_unique')) {
            Schema::table('task_vms', static function (Blueprint $table): void {
                $table->dropUnique('task_vms_host_slot_unique');
            });
        }

        $columns = array_values(array_filter(
            ['slot', 'identity_revision', 'bridge_address', 'bridge_mac'],
            static fn (string $column): bool => Schema::hasColumn('task_vms', $column),
        ));

        if ($columns !== []) {
            Schema::table('task_vms', static function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    public function down(): void
    {
        // The slot columns are not restored: nothing reads them.
    }
};
