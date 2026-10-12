<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UpCloud task VMs: an `upcloud` row has no host Node. `provider_id` is the UpCloud server UUID, and
 * `ssh_host_key` the ed25519 host key line that the Gateway generated for the VM and pinned. The partial unique
 * indexes are created again, because a column change rebuilds the SQLite table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS task_vms_live_group_id_unique');
        DB::statement('DROP INDEX IF EXISTS task_vms_live_wireguard_ip_unique');
        Schema::table('task_vms', function (Blueprint $table): void {
            $table->foreignId('host_node_id')->nullable()->change();
            $table->string('provider_id')->nullable()->after('provider');
            $table->text('ssh_host_key')->nullable()->after('provider_id');
        });
        DB::statement("CREATE UNIQUE INDEX task_vms_live_group_id_unique ON task_vms (group_id) WHERE state != 'destroyed'");
        DB::statement("CREATE UNIQUE INDEX task_vms_live_wireguard_ip_unique ON task_vms (wireguard_ip) WHERE state != 'destroyed'");
    }

    public function down(): void
    {
        if (DB::table('task_vms')->where('provider', 'upcloud')->where('state', '!=', 'destroyed')->exists()) {
            throw new RuntimeException('Destroy the UpCloud task VMs before rolling back the UpCloud overflow.');
        }
        DB::statement('DROP INDEX IF EXISTS task_vms_live_group_id_unique');
        DB::statement('DROP INDEX IF EXISTS task_vms_live_wireguard_ip_unique');
        DB::table('task_vms')->whereNull('host_node_id')->delete();
        Schema::table('task_vms', function (Blueprint $table): void {
            $table->dropColumn(['provider_id', 'ssh_host_key']);
            $table->foreignId('host_node_id')->nullable(false)->change();
        });
        DB::statement("CREATE UNIQUE INDEX task_vms_live_group_id_unique ON task_vms (group_id) WHERE state != 'destroyed'");
        DB::statement("CREATE UNIQUE INDEX task_vms_live_wireguard_ip_unique ON task_vms (wireguard_ip) WHERE state != 'destroyed'");
    }
};
