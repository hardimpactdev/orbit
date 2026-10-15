<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('task_vms')->whereNull('group_id')->where('state', '!=', 'destroyed')
            ->update(['state' => 'destroying']);
        DB::table('task_vms')->where('state', 'warm')->update([
            'state' => 'failed', 'error_code' => 'task_vm.warm_pool_removed',
            'error_message' => 'The warm pool was removed. Cancel this group and allocate a new task VM.',
        ]);
        foreach (DB::table('nodes')->whereNotNull('settings')->get(['id', 'settings']) as $node) {
            $settings = json_decode((string) $node->settings, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($settings) && is_array($settings['task_vms'] ?? null) && array_key_exists('warm_vms', $settings['task_vms'])) {
                unset($settings['task_vms']['warm_vms']);
                DB::table('nodes')->where('id', $node->id)->update(['settings' => json_encode($settings, JSON_THROW_ON_ERROR)]);
            }
        }
    }

    public function down(): void
    {
        // Retired VMs stay retired; rollback must never make one claimable again.
    }
};
