<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Retire cloud-only task VM state after the provider inventory has been closed. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('task_vms')->where('provider', 'upcloud')->where('state', '!=', 'destroyed')->exists()) {
            throw new RuntimeException('Remove the live UpCloud task VMs before retiring their support.');
        }
        DB::table('task_vms')->where('provider', 'upcloud')->delete();
        Schema::dropIfExists('upcloud_task_vm_image_builds');
        foreach (['task_vms' => ['provider_id', 'provider_bootstrap', 'ssh_host_key'], 'task_vm_image_recipe' => ['upcloud_rebuild_after_attempt']] as $name => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($name, $column)) {
                    Schema::table($name, static function (Blueprint $table) use ($column): void {
                        $table->dropColumn($column);
                    });
                }
            }
        }
    }
};
