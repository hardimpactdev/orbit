<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

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
     * The create of a copy ended. A success runs the group; a failure or cancel fails the group, which keeps owning the copy until the
     * queued destroy removes it.
     */
    public function createEnded(Operation $operation, string $status): void
    {
        $owned = $this->owned($operation->target_id);
        if ($owned === null || $owned[1]->status !== TaskGroupStatus::Reserved) {
            return;
        }
        [$instance, $group] = $owned;
        if ($status === 'succeeded') {
            $group->update(['status' => TaskGroupStatus::Running, 'started_at' => $group->started_at ?? now(), 'reserved_at' => null, 'capacity_wait_reason' => null]);

            return;
        }
        $group->update(['status' => TaskGroupStatus::Failed, 'settled_at' => now(), 'reserved_at' => null, 'capacity_wait_reason' => null]);
        DestroyTaskCopy::dispatch($instance->id);
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
