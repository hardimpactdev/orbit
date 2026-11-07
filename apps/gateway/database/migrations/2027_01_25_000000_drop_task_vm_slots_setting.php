<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task VMs have no fixed slot identities any more: a slot is only a place under a host's `max_vms` (docs/cli/tasks/provision.mmd). The
 * `slots` key goes from every stored task VM host setting, and the slot digest column with it. A slot number comes back with each task
 * start, so a name is unique among the rows that are not destroyed, as is a slot number on its host.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('nodes')->whereNotNull('settings')->get(['id', 'settings']) as $row) {
            $settings = json_decode((string) $row->settings, true);
            if (! is_array($settings) || ! is_array($settings['task_vms'] ?? null) || ! array_key_exists('slots', $settings['task_vms'])) {
                continue;
            }
            unset($settings['task_vms']['slots']);
            DB::table('nodes')->where('id', $row->id)->update(['settings' => json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        }
        Schema::table('task_vms', static function (Blueprint $table): void {
            $table->dropColumn('slot_digest');
        });
        DB::statement('DROP INDEX task_vms_name_unique');
        DB::statement("CREATE UNIQUE INDEX task_vms_name_unique ON task_vms(name) WHERE state != 'destroyed'");
        DB::statement("CREATE UNIQUE INDEX task_vms_host_slot_unique ON task_vms(host_node_id, slot_number) WHERE slot_number IS NOT NULL AND state != 'destroyed'");
    }
};
