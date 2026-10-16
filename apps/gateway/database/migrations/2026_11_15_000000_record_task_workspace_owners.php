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
 *
 * A group holds only the workspace it owns. A group whose Instance did not get that group as its owner loses the
 * attachment. A group that has not ended then asks for assistance with a fixed reason. Rollback keeps those groups
 * detached.
 *
 * On SQLite a trigger keeps a set owner from changing. Clearing it, as deleting the group does, stays possible.
 */
return new class extends Migration
{
    private const string DetachedReason = 'Orbit detached the Instance of this task because the task does not own it. Cancel the task and start a new one.';

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

        $unowned = DB::table('tasks')
            ->leftJoin('instances', 'instances.id', '=', 'tasks.taskable_id')
            ->whereNull('tasks.parent_id')
            ->whereNotNull('tasks.taskable_id')
            ->where(static fn ($owner) => $owner->whereNull('instances.task_workspace_owner_id')->orWhereColumn('instances.task_workspace_owner_id', '!=', 'tasks.id'))
            ->pluck('tasks.id');
        foreach ($unowned->chunk(500) as $groups) {
            DB::table('tasks')->whereIn('id', $groups)->update(['taskable_type' => null, 'taskable_id' => null]);
            DB::table('tasks')->whereIn('id', $groups)
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->where(static fn ($open) => $open->where('assistance_requested', false)->orWhereNull('assistance_kind')->orWhere('assistance_kind', '!=', 'direction'))
                ->update(['assistance_requested' => true, 'assistance_kind' => 'failure', 'assistance_question' => null, 'assistance_reason' => self::DetachedReason]);
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER instances_task_workspace_owner_immutable
                BEFORE UPDATE OF task_workspace_owner_id ON instances
                WHEN NEW.task_workspace_owner_id IS NOT NULL AND NEW.task_workspace_owner_id IS NOT OLD.task_workspace_owner_id
                BEGIN
                    SELECT RAISE(ABORT, 'A task workspace owner never changes.');
                END
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER instances_task_workspace_owner_immutable');
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
