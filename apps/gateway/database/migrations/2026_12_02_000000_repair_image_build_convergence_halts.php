<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('task_vm_image_builds')->whereNotNull('fleet_convergence')->eachById(function (object $record): void {
            $progress = json_decode($record->fleet_convergence, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($progress) || ($progress['status'] ?? null) !== 'halted') {
                return;
            }
            // Only the exact former image-build failure message proves this is not a Node-visit halt.
            foreach (DB::table('task_vm_image_builds')->where('recipe_identity', $record->recipe_identity)
                ->where('recipe_generation', $progress['generation'] ?? null)->get() as $build) {
                $message = "Machine recipe [{$record->recipe_identity}] halted: Image build [{$build->id}] failed on host [{$build->host_node_id}]. Inspect its build steps before recovery. No automatic rollback or retry.";
                if (($progress['message'] ?? null) !== $message) {
                    continue;
                }
                $progress['status'] = 'ready';
                unset($progress['message'], $progress['finished_at'], $progress['alert']);
                DB::table('task_vm_image_builds')->where('id', $record->id)->update([
                    'fleet_convergence' => json_encode($progress, JSON_THROW_ON_ERROR),
                ]);

                return;
            }
        });
    }

    public function down(): void
    {
        // A build failure must not become a convergence halt again.
    }
};
