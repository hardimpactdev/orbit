<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Actions\TaskVms\WakeTaskVmAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskWait;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmFailedException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskVm;

/**
 * The claim side of a `vm` group: the Node of its ready task VM. Until the VM is ready, the
 * claim waits with a `Task VM:` reason and tries again on the next tick. The first wait allocates the VM. A `failed`
 * VM is not a wait: the claim asks for assistance, and the group keeps that VM until it is cancelled.
 *
 * It reads `TaskVmSettings` only here, never when the scheduler is built, so an invalid task VM value
 * makes `vm` groups wait and leaves shared groups alone.
 */
final readonly class TaskVmWorkspace
{
    /**
     * @throws TaskCapacityException while the group's task VM is not ready
     * @throws TaskVmFailedException when the group's task VM failed
     */
    public function node(Task $group): Node
    {
        $vm = TaskVm::forGroup($group);
        if (! $vm instanceof TaskVm) {
            try {
                if (! app(TaskVmSettings::class)->enabled) {
                    throw new TaskCapacityException(TaskWait::TaskVm, 'Task VM: task VMs are not enabled on this Gateway.');
                }
                $vm = app(AllocateTaskVmAction::class)->execute($group);
            } catch (TaskVmException $exception) {
                throw new TaskCapacityException(TaskWait::TaskVm, "Task VM: {$exception->errorCode}: {$exception->getMessage()}");
            }
        } elseif ($vm->state === TaskVmState::Provisioning && $vm->warmed_at !== null && $vm->claimed_at !== null) {
            // Later claims recover a warm claim committed before its job was accepted.
            // Cold allocations wait for provisioning and enrollment to enqueue their own preparation.
            app(AllocateTaskVmAction::class)->dispatchPreparation($vm);
        }

        if ($vm->state === TaskVmState::Failed) {
            throw new TaskVmFailedException($vm);
        }
        $node = $vm->node;
        if ($vm->state === TaskVmState::Ready && $node instanceof Node && $node->status === LifecycleStatus::Active) {
            return $node;
        }

        if ($vm->state === TaskVmState::Parked) {
            app(WakeTaskVmAction::class)->forVm($vm);
        }

        throw new TaskCapacityException(TaskWait::TaskVm, match ($vm->state) {
            TaskVmState::Destroying => 'Task VM: destroying.',
            TaskVmState::Parked => WakeTaskVmAction::Resuming,
            default => 'Task VM: provisioning.',
        });
    }

    /** The group's task VM that is not destroyed, or null for a group without one. */
    public function vm(Task $group): ?TaskVm
    {
        return TaskVm::forGroup($group);
    }
}
