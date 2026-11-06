<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Routes\NativeRouteRemovalCompletion;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmDestroyProjection;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;

/**
 * The records of a task group after its VM is gone, from the cleanup input that admission captured. The cancel of a group and the
 * end of a proof run both settle through it, inside the transaction that holds the locked group.
 */
final readonly class TaskGroupEnding
{
    /** Forgets the workspace and the VM, and cancels the group with its open subtasks.
     * @param  array<string,mixed>  $captured
     */
    public function settle(Task $group, array $captured, string $kind): void
    {
        if ($group->taskable_id !== null) {
            $instance = Instance::query()->where('task_workspace_owner_id', $group->id)->where('project_id', $group->project_id)
                ->lockForUpdate()->findOrFail($group->taskable_id);
            $vm = TaskVm::query()->lockForUpdate()->findOrFail(ValidatedData::integer($captured['task_vm_id']));
            if ($instance->node_id !== $vm->node_id || $vm->group_id !== $group->id) {
                throw new ResourceOperationException('operation.execution_forbidden', 'Cleanup no longer owns its workspace VM.', 422);
            }
            foreach (RouteExecutionProof::objects($captured['workspace_routes'] ?? []) as $route) {
                app(NativeRouteRemovalCompletion::class)->apply(['routes' => [['route_id' => ValidatedData::integer($route['route_id']), 'domain' => ValidatedData::string($route['domain']), 'replacement_of' => null]]]);
            }
            $instance->delete();
        }
        if (($captured['task_vm_id'] ?? null) !== null) {
            $vm = TaskVm::query()->lockForUpdate()->findOrFail(ValidatedData::integer($captured['task_vm_id']));
            app(TaskVmDestroyProjection::class)->record($vm, $captured);
        }
        $group->taskable()->dissociate();
        $group->fill(['status' => $kind === 'tasks.maintenance_finish' || $group->status === TaskGroupStatus::Completed ? TaskGroupStatus::Completed : TaskGroupStatus::Cancelled,
            'settled_at' => now(), 'reserved_at' => null, 'capacity_wait_reason' => null])->save();
        $group->tasks()->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])
            ->update(['status' => TaskStatus::Cancelled, 'settled_at' => now()]);
    }

    /** Incomplete cleanup keeps the group and workspace for another cancel; only its captured VM cleanup fails.
     * @param  array<string,mixed>  $captured
     */
    public function failed(array $captured, ?string $errorCode): void
    {
        if (($captured['task_vm_id'] ?? null) !== null) {
            app(TaskVmDestroyProjection::class)->failed(TaskVm::query()->lockForUpdate()->find(ValidatedData::integer($captured['task_vm_id'])), $captured, $errorCode);
        }
    }
}
