<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Read the deploy environment even when Laravel's config was cached before migrations.
        $raw = $_ENV['ORBIT_TASK_VMS_INCUS_HOSTS'] ?? $_SERVER['ORBIT_TASK_VMS_INCUS_HOSTS'] ?? (getenv('ORBIT_TASK_VMS_INCUS_HOSTS') ?: null);
        if ($raw === null && is_file(app()->environmentFilePath())) {
            $contents = file_get_contents(app()->environmentFilePath());
            $raw = Dotenv::parse($contents === false ? '' : $contents)['ORBIT_TASK_VMS_INCUS_HOSTS'] ?? null;
        }
        if ($raw === null || $raw === '') {
            return;
        }
        if (! is_string($raw)) {
            throw new RuntimeException('ORBIT_TASK_VMS_INCUS_HOSTS must be a JSON string for migration.');
        }
        $entries = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new RuntimeException('ORBIT_TASK_VMS_INCUS_HOSTS must be a JSON list for migration.');
        }
        DB::transaction(function () use ($entries): void {
            foreach ($entries as $entry) {
                if (! is_array($entry) || ! is_int($entry['node_id'] ?? null)) {
                    throw new RuntimeException('Task VM migration requires an integer node_id.');
                }
                $node = DB::table('nodes')->where('id', $entry['node_id'])->lockForUpdate()->first();
                if ($node === null) {
                    throw new RuntimeException("Task VM host Node [{$entry['node_id']}] is missing.");
                }
                $settings = json_decode((string) ($node->settings ?? '{}'), true, flags: JSON_THROW_ON_ERROR) ?? [];
                if (! is_array($settings)) {
                    throw new RuntimeException('Task VM host settings must be an object.');
                }
                if (array_key_exists('task_vms', $settings)) {
                    continue;
                }
                unset($entry['node_id']);
                $settings['task_vms'] = [
                    'project' => 'orbit-tasks', 'network' => 'orbittask0', 'pool' => 'orbit-tasks',
                    'image_alias' => 'orbit-task-base', 'zfs_dataset' => null,
                    'cpus' => 2, 'memory' => '4GiB', 'disk' => '20GiB',
                    'warm_vms' => 0, 'operator_owned' => [], ...$entry,
                ];
                DB::table('nodes')->where('id', $node->id)->update(['settings' => json_encode($settings, JSON_THROW_ON_ERROR)]);
            }
        });
    }

    public function down(): void
    {
        // Operator intent must survive rollback. Do not erase settings or recreate an env dependency.
    }
};
