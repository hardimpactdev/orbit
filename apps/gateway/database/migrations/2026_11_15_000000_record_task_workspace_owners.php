<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records the task group that owns each task workspace. Task cleanup removes an Instance only through this owner.
 *
 * Existing owners come from explicit records only, never from an Instance name or branch:
 * - an Instance on the Node of a task VM that is not destroyed belongs to the VM's group;
 * - any other Instance belongs to the one top-level group of its Project that has it as its Instance.
 * An Instance that no record names, that several groups have, or that shares its group with another Instance gets no
 * owner. Task cleanup never removes it. Deleting the group clears the owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // Preserve cross-table triggers by avoiding SQLite's table rebuild.
            DB::statement('ALTER TABLE instances ADD COLUMN task_workspace_owner_id integer NULL REFERENCES tasks(id) ON DELETE SET NULL');
        } else {
            Schema::table('instances', static function (Blueprint $table): void {
                $table->foreignId('task_workspace_owner_id')->nullable()->constrained('tasks')->nullOnDelete();
            });
        }
        Schema::table('instances', static fn (Blueprint $table) => $table->unique('task_workspace_owner_id'));

        $placed = DB::table('instances')
            ->join('task_vms', 'task_vms.node_id', '=', 'instances.node_id')
            ->where('task_vms.state', '!=', 'destroyed')
            ->whereNotNull('task_vms.group_id')
            ->pluck('task_vms.group_id', 'instances.id');
        $attached = DB::table('tasks')
            ->join('instances', static fn ($join) => $join->on('instances.id', '=', 'tasks.taskable_id')->on('instances.project_id', '=', 'tasks.project_id'))
            ->whereNull('tasks.parent_id')
            ->where('tasks.taskable_type', 'instance')
            ->groupBy('tasks.taskable_id')
            ->havingRaw('count(*) = 1')
            ->selectRaw('tasks.taskable_id as instance_id, min(tasks.id) as group_id')
            ->pluck('group_id', 'instance_id');

        $owners = $attached->replace($placed)->map(static fn (mixed $group): int => is_numeric($group) ? (int) $group : throw new RuntimeException('Expected an integer task id.'));
        $shared = $owners->countBy()->filter(static fn (int $instances): bool => $instances > 1)->keys();
        foreach ($owners->reject(static fn (int $group): bool => $shared->contains($group)) as $instance => $group) {
            DB::table('instances')->where('id', $instance)->update(['task_workspace_owner_id' => $group]);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('instances', static fn (Blueprint $table) => $table->dropUnique(['task_workspace_owner_id']));
            DB::statement('ALTER TABLE instances DROP COLUMN task_workspace_owner_id');

            return;
        }

        Schema::table('instances', static function (Blueprint $table): void {
            $table->dropForeign(['task_workspace_owner_id']);
            $table->dropUnique(['task_workspace_owner_id']);
            $table->dropColumn('task_workspace_owner_id');
        });
    }
};
