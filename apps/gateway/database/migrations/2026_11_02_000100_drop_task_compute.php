<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every task group runs on its own task VM, so the compute mode goes: `projects.task_compute`, `tasks.task_compute`,
 * and `task_comments.topology_resume`, which only the shared-mode `TASK-<group>` topology request used. The migration
 * refuses while a Project or an open group still uses shared compute. Rolling back restores the columns: Projects and
 * claimed groups read `vm`, and the rows of finished shared groups do not come back.
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
            ->where(static fn ($query) => $query->where('sql', 'like', '%task_compute%')->orWhere('sql', 'like', '%topology_resume%'))->exists()) {
            throw new RuntimeException('A schema object still reads the task compute mode.');
        }

        DB::transaction(static function (): void {
            DB::statement('ALTER TABLE projects DROP COLUMN task_compute');
            DB::statement('ALTER TABLE tasks DROP COLUMN task_compute');
            DB::statement('ALTER TABLE task_comments DROP COLUMN topology_resume');
        });
    }

    public function down(): void
    {
        DB::transaction(static function (): void {
            DB::statement("ALTER TABLE projects ADD COLUMN task_compute varchar NOT NULL DEFAULT 'vm'");
            DB::statement('ALTER TABLE tasks ADD COLUMN task_compute varchar');
            DB::statement('ALTER TABLE task_comments ADD COLUMN topology_resume text');
            DB::table('tasks')->whereNull('parent_id')->whereNotIn('status', ['backlog', 'todo'])->update(['task_compute' => 'vm']);
        });
    }
};
