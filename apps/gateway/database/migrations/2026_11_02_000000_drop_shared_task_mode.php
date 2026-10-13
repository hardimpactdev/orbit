<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every task group runs on its own task VM, so shared task mode goes in one step: `projects.task_compute`,
 * `tasks.task_compute`, `task_comments.topology_resume` (only the shared `TASK-<group>` topology request used it),
 * `instances.worker_shared`, and the `tasks` section of Node settings, which held only `tasks.worker`.
 *
 * The migration refuses before it changes anything while a Project or an open group still uses shared compute, or a
 * schema object reads a dropped column, so a refusal leaves the old schema whole. The columns are dropped in place,
 * because rebuilding `instances` would invalidate the cross-table triggers. Rolling back restores the columns: Projects
 * and claimed groups read `vm`, and the values of finished shared groups, `worker_shared`, and the settings do not
 * come back.
 */
return new class extends Migration
{
    public function up(): void
    {
        $open = ['backlog', 'todo', 'reserved', 'running', 'reviewing', 'settling', 'waiting_for_review'];
        if (DB::table('projects')->where('task_compute', 'shared')->exists()
            || DB::table('tasks')->whereNull('parent_id')->where('task_compute', 'shared')->whereIn('status', $open)->exists()) {
            throw new RuntimeException('Switch every Project and open task group to vm compute before dropping the compute mode.');
        }
        if (DB::table('sqlite_master')->whereIn('type', ['trigger', 'index', 'view'])
            ->where(static fn ($query) => $query->where('sql', 'like', '%task_compute%')->orWhere('sql', 'like', '%topology_resume%')
                ->orWhere('sql', 'like', '%worker_shared%'))->exists()) {
            throw new RuntimeException('A schema object still reads the shared task mode.');
        }

        DB::transaction(static function (): void {
            DB::statement('ALTER TABLE projects DROP COLUMN task_compute');
            DB::statement('ALTER TABLE tasks DROP COLUMN task_compute');
            DB::statement('ALTER TABLE task_comments DROP COLUMN topology_resume');
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
        DB::transaction(static function (): void {
            DB::statement("ALTER TABLE projects ADD COLUMN task_compute varchar NOT NULL DEFAULT 'vm'");
            DB::statement('ALTER TABLE tasks ADD COLUMN task_compute varchar');
            DB::statement('ALTER TABLE task_comments ADD COLUMN topology_resume text');
            DB::statement("ALTER TABLE instances ADD COLUMN worker_shared tinyint(1) NOT NULL DEFAULT '0'");
            DB::table('tasks')->whereNull('parent_id')->whereNotIn('status', ['backlog', 'todo'])->update(['task_compute' => 'vm']);
        });
    }
};
