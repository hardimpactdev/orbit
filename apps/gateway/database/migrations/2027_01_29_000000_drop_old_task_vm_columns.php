<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The task VM columns that only the deleted `task_vm.provision` and `task_vm.destroy` paths used: the UpCloud provision bootstrap key pair
 * and the guest Swamp worker flag of the destroy. Each drop holds when it ran before, because a run that stopped half way must not break
 * the release on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['provider_bootstrap', 'worker_stop_required'] as $column) {
            if (Schema::hasColumn('task_vms', $column)) {
                Schema::table('task_vms', static function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
