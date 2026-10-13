<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task VMs are the only task mode, so the task worker account is gone. Drops `instances.worker_shared` and strips the
 * `tasks` section, which held only `tasks.worker`, from Node settings. The column is dropped in place, because
 * rebuilding `instances` would invalidate the cross-table triggers; no trigger or index reads it. Rolling back
 * restores the column, not its values or the settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE instances DROP COLUMN worker_shared');

            foreach (DB::table('nodes')->whereNotNull('settings')->get(['id', 'settings']) as $node) {
                $settings = json_decode((string) $node->settings, true);
                if (! is_array($settings) || ! array_key_exists('tasks', $settings)) {
                    continue;
                }
                unset($settings['tasks']);
                DB::table('nodes')->where('id', $node->id)->update([
                    'settings' => $settings === [] ? null : json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table): void {
            $table->boolean('worker_shared')->default(false);
        });
    }
};
