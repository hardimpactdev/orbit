<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\OperationStatus;
use App\Jobs\Tasks\DestroyTaskCopy;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;

/**
 * What the end of a task copy's create or destroy does to the group that owns the copy (docs/cli/task/start.mmd). An Instance that no
 * group owns, or that has no container, is no task copy: both calls then change nothing. Each runs inside its projection's transaction.
 */
final readonly class TaskCopyLifecycle
{
    /**
     * The create of a copy ended, however it ended. A success runs a group that waits for it. An expired budget changes nothing: the
     * run may still be stopping or be reopened, and the group stays reserved with its copy until the create ends again or `task:cancel`
     * destroys the copy. Any other end fails a group that still runs and queues the copy's destroy.
     */
    public function createEnded(Operation $operation): void
    {
        $owned = $this->owned($operation->target_id);
        if ($owned === null) {
            return;
        }
        [$instance, $group] = $owned;
        if ($operation->error_code === 'operation.timed_out') {
            return;
        }
        if (self::ready($operation)) {
            if ($group->status === TaskGroupStatus::Reserved) {
                $group->update(['status' => TaskGroupStatus::Running, 'started_at' => $group->started_at ?? now(), 'reserved_at' => null, 'capacity_wait_reason' => null]);
            }

            return;
        }
        if (in_array($group->status, TaskGroupStatus::active(), true)) {
            $group->update(['status' => TaskGroupStatus::Failed, 'settled_at' => now(), 'reserved_at' => null, 'capacity_wait_reason' => null]);
        }
        DestroyTaskCopy::dispatch($instance->id);
    }

    /**
     * Whether the copy is ready for its task: the one place that decides it. Today the create Operation succeeded; for a nested-fleet
     * copy that includes its fleet step, which a create runs before it can succeed. When the create splits into a first workflow (usable)
     * and a second one (Processes, Route, database copy), this reads the field that names "fully set up" instead. Read no step names here.
     */
    public static function ready(Operation $operation): bool
    {
        return $operation->status === OperationStatus::Succeeded;
    }

    /**
     * The destroy of a copy succeeded, before its row goes. The group lets go of it; a group that still runs is cancelled with its open
     * subtasks, because its work place is gone.
     */
    public function destroyed(int $instanceId): void
    {
        $group = $this->owned($instanceId)[1] ?? null;
        if ($group === null) {
            return;
        }
        if (in_array($group->status, TaskGroupStatus::active(), true)) {
            app(TaskGroupEnding::class)->settle($group, [], 'tasks.cancel');

            return;
        }
        $group->taskable()->dissociate();
        $group->save();
    }

    /** @return array{0: Instance, 1: Task}|null The copy and the group that owns it and is bound to it. */
    private function owned(int $instanceId): ?array
    {
        $instance = Instance::query()->find($instanceId);
        if ($instance === null || $instance->task_workspace_owner_id === null || ! $instance->runtime()->exists()) {
            return null;
        }
        $group = Task::topLevel()->lockForUpdate()->find($instance->task_workspace_owner_id);

        return $group === null || $group->taskable_id !== $instance->id || $group->taskable_type !== $instance->getMorphClass() ? null : [$instance, $group];
    }
}
