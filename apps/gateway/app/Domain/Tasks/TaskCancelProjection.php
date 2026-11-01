<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Routes\NativeRouteRemovalCompletion;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmDestroyProjection;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Complete cleanup alone may forget workspace records and settle cancellation. */
final readonly class TaskCancelProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return app(TaskCancelIntent::class)->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($operation->status->terminal()) {
                return $operation;
            }
            if ($data['status'] === 'succeeded') {
                app(TaskCancelIntent::class)->input($operation);
            }
            $group = Task::topLevel()->lockForUpdate()->findOrFail($operation->target_id);
            $result = ValidatedData::object($data['result'] ?? []);
            if ($data['status'] === 'succeeded') {
                if (($result['group_id'] ?? null) !== $group->id || ($result['instance_id'] ?? null) !== ($operation->input['instance_id'] ?? null)
                    || ($result['cleanup_complete'] ?? null) !== true) {
                    throw new ResourceOperationException('operation.invalid_result', 'Cancellation requires its exact complete cleanup receipt.', 422);
                }
                if ($group->taskable_id !== null) {
                    $instance = Instance::query()->where('task_workspace_owner_id', $group->id)->where('project_id', $group->project_id)
                        ->lockForUpdate()->findOrFail($group->taskable_id);
                    $vm = TaskVm::query()->lockForUpdate()->findOrFail(ValidatedData::integer($operation->input['task_vm_id']));
                    if ($instance->node_id !== $vm->node_id || $vm->group_id !== $group->id) {
                        throw new ResourceOperationException('operation.execution_forbidden', 'Cleanup no longer owns its workspace VM.', 422);
                    }
                    foreach (RouteExecutionProof::objects($operation->input['workspace_routes'] ?? []) as $route) {
                        app(NativeRouteRemovalCompletion::class)->apply(['routes' => [['route_id' => ValidatedData::integer($route['route_id']), 'domain' => ValidatedData::string($route['domain']), 'replacement_of' => null]]]);
                    }
                    $instance->delete();
                }
                if (($operation->input['task_vm_id'] ?? null) !== null) {
                    $vm = TaskVm::query()->lockForUpdate()->findOrFail(ValidatedData::integer($operation->input['task_vm_id']));
                    app(TaskVmDestroyProjection::class)->record($vm, $operation->input, ValidatedData::object($result['vm']));
                }
                $group->taskable()->dissociate();
                $group->fill(['status' => $operation->kind === 'tasks.maintenance_finish' || $group->status === TaskGroupStatus::Completed ? TaskGroupStatus::Completed : TaskGroupStatus::Cancelled, 'settled_at' => now(), 'reserved_at' => null, 'capacity_wait_reason' => null])->save();
                $group->tasks()->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])
                    ->update(['status' => TaskStatus::Cancelled, 'settled_at' => now()]);
            }

            return app(DirectOperation::class)->project($operation, $data, $result);
        });
    }
}
