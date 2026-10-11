<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Warm task VMs: a pool row has no group and no Pi token until a claim takes it. `claimed_at` is when a group
 * got the row, `warmed_at` when it became `warm`, and `image_fingerprint` the base image a warm VM launched,
 * read back from its host. The partial unique indexes are created again, because a column change rebuilds the
 * SQLite table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS task_vms_live_group_id_unique');
        DB::statement('DROP INDEX IF EXISTS task_vms_live_wireguard_ip_unique');
        Schema::table('task_vms', function (Blueprint $table): void {
            $table->foreignId('group_id')->nullable()->change();
            $table->text('pi_token')->nullable()->change();
            $table->timestamp('claimed_at')->nullable()->after('model_proxy_origin');
            $table->timestamp('warmed_at')->nullable()->after('claimed_at');
            $table->string('image_fingerprint')->nullable()->after('warmed_at');
        });
        DB::statement("CREATE UNIQUE INDEX task_vms_live_group_id_unique ON task_vms (group_id) WHERE state != 'destroyed'");
        DB::statement("CREATE UNIQUE INDEX task_vms_live_wireguard_ip_unique ON task_vms (wireguard_ip) WHERE state != 'destroyed'");
        DB::table('task_vms')->whereNull('claimed_at')->update(['claimed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        if (DB::table('task_vms')->where('state', '!=', 'destroyed')->whereNull('group_id')->exists()) {
            throw new RuntimeException('Destroy the warm task VMs before rolling back the warm pool.');
        }
        DB::statement('DROP INDEX IF EXISTS task_vms_live_group_id_unique');
        DB::statement('DROP INDEX IF EXISTS task_vms_live_wireguard_ip_unique');
        DB::table('task_vms')->whereNull('group_id')->delete();
        DB::table('task_vms')->whereNull('pi_token')->update(['pi_token' => '']);
        Schema::table('task_vms', function (Blueprint $table): void {
            $table->dropColumn(['claimed_at', 'warmed_at', 'image_fingerprint']);
            $table->foreignId('group_id')->nullable(false)->change();
            $table->text('pi_token')->nullable(false)->change();
        });
        DB::statement("CREATE UNIQUE INDEX task_vms_live_group_id_unique ON task_vms (group_id) WHERE state != 'destroyed'");
        DB::statement("CREATE UNIQUE INDEX task_vms_live_wireguard_ip_unique ON task_vms (wireguard_ip) WHERE state != 'destroyed'");
    }
};
