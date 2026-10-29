<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskFinalReview;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\VmObservation;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;

/** The existing workspace preservation rule, shared by executor reads and the final deletion guard. */
final readonly class TaskVmPreservation
{
    /** @return array{destroy_allowed:bool,needs_wake:bool,pending_commit:?string} */
    public function read(TaskVm $vm, ?VmObservation $power): array
    {
        $group = $vm->group;
        $workspace = $vm->node_id !== null && $group !== null && $group->workspace()->where('node_id', $vm->node_id)->exists();
        $pending = $group === null ? null : TaskFinalReview::unpublishedRemovalCommit($group);
        $retry = $group !== null && Task::topLevel()->whereKey($group->id)->workspaceDestructionRetry()->exists();

        return [
            'destroy_allowed' => ! $workspace || in_array($vm->state, [TaskVmState::Failed, TaskVmState::Destroying], true)
                || ($retry && $pending === null && $power?->running !== true),
            'needs_wake' => $workspace && $pending !== null && $power !== null && ! $power->running && $vm->state !== TaskVmState::Failed,
            'pending_commit' => $pending,
        ];
    }

    public function assertAllowed(Operation $effect, TaskVm $vm, ?VmObservation $power): void
    {
        $parent = $effect->parent_operation_id === null ? null : Operation::query()->find($effect->parent_operation_id);
        if ($parent === null || $parent->kind !== 'task_vm.destroy' || $parent->target_type !== 'task_vm' || $parent->target_id !== $vm->id
            || app(OperationChildren::class)->child($parent, 'destroy-intent')->id !== $effect->id) {
            throw new ResourceOperationException('operation.completion_unproven', 'Deletion requires the owning VM destruction intent.', 409);
        }
        if ($this->read($vm, $power)['destroy_allowed']) {
            return;
        }
        if ($vm->worker_stop_required) {
            $children = app(OperationChildren::class);
            $stop = $children->child($parent, 'stop-worker');
            $powerOff = $children->child($parent, 'worker-power-off');
            $powerKind = $vm->provider === TaskVmProviderKind::Incus ? 'task_vm.incus_stop' : 'task_vm.upcloud_hard_stop';
            if ($stop->kind === 'task_vm.stop_worker' && $powerOff->kind === $powerKind
                && $stop->status === OperationStatus::Failed && $powerOff->status === OperationStatus::Failed) {
                return;
            }
        }

        throw new ResourceOperationException('task_vm.workspace_not_preserved', 'The task workspace must finish preservation before VM deletion.', 409);
    }

    public function assertDeletion(Operation $effect, TaskVm $vm): void
    {
        $parent = $effect->parent_operation_id === null ? null : Operation::query()->find($effect->parent_operation_id);
        if ($parent === null || $parent->kind !== 'task_vm.destroy' || $parent->target_id !== $vm->id || $vm->state !== TaskVmState::Destroying) {
            throw new ResourceOperationException('operation.completion_unproven', 'The VM has no proven destruction boundary.', 409);
        }
        app(OperationChildren::class)->requireSucceeded($parent, 'destroy-intent');
        if (($parent->input['key'] ?? null) !== null) {
            app(OperationChildren::class)->requireSucceeded($parent, 'revoke-model-key');
        }
    }
}
