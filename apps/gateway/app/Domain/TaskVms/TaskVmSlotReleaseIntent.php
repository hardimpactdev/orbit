<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;

/**
 * The release of a task VM slot: the full workflow input of the host steps. A slot keeps its Node, keys, tokens and
 * identity volume, so the run deletes only the VM and its seed clone and holds no secret. See
 * docs/cli/tasks/vm-slot-release.mmd.
 */
final readonly class TaskVmSlotReleaseIntent
{
    /** The states a release starts from. A `free` slot has nothing to release. */
    private const array Releasable = [TaskVmState::Provisioning, TaskVmState::Ready, TaskVmState::Parked, TaskVmState::Failed, TaskVmState::Destroying];

    public function __construct(private TaskVmSettings $settings, private SwampSshExecutionInput $ssh) {}

    /**
     * Whether a task still holds the slot: its group is open, or it ended with its workspace still in place. Such a
     * workspace needs the ending run first (push of approved work, Route withdrawal, Project teardown in the guest). A
     * failed claim can have published its Route, so it needs that run too.
     */
    public function held(TaskVm $vm): bool
    {
        $group = $vm->group;

        return $group === null || ! in_array($group->status, TaskGroupStatus::endedStatuses(), true) || $group->taskable_id !== null;
    }

    /**
     * Whether the slot's task ended but still owns its workspace. The ending run (`tasks.cancel`) removes the workspace
     * and then admits the release, so such a slot is ended, not released.
     */
    public function endable(TaskVm $vm): bool
    {
        $group = $vm->group;

        return $this->enrolled($vm) && $group !== null && in_array($group->status, TaskGroupStatus::endedStatuses(), true) && $group->taskable_id !== null;
    }

    /**
     * Whether the host sweep may release this slot: an enrolled slot that no task holds any more but that still records
     * the ended task. A slot with no task is `free`, or still enrolling, and has nothing to release.
     */
    public function sweepable(TaskVm $vm): bool
    {
        return $this->enrolled($vm) && ! $this->held($vm);
    }

    private function enrolled(TaskVm $vm): bool
    {
        return $vm->slot !== null && $vm->identity_revision !== null && $vm->group_id !== null && in_array($vm->state, self::Releasable, true);
    }

    /** @return array<string,mixed> */
    public function capture(TaskVm $vm): array
    {
        $vm->refresh();
        $host = $vm->hostNode;
        if ($vm->slot === null || $vm->identity_revision === null || $vm->group_id === null || ! in_array($vm->state, self::Releasable, true) || ! $vm->node instanceof Node || ! $host instanceof Node) {
            throw new ResourceOperationException('task_vm.slot_invalid', 'A release needs an enrolled slot that records an ended task.', 409);
        }
        if ($this->held($vm)) {
            throw new ResourceOperationException('task_vm.slot_held', 'A task still holds this slot. Release it after the task ends.', 409);
        }
        // The ending run of the task, or another slot Operation, must settle first: the release deletes what they work on.
        $busy = Operation::query()->whereNotIn('status', OperationStatus::terminalValues())->where(static fn ($q) => $q
            ->where(static fn ($q) => $q->where('target_type', 'task_vm')->where('target_id', $vm->id))
            ->orWhere(static fn ($q) => $q->where('target_type', 'task_group')->where('target_id', $vm->group_id))
            ->orWhere(static fn ($q) => $q->where('target_type', 'node')->where('target_id', $vm->node_id)))->exists();
        if ($busy) {
            throw new ResourceOperationException('operation.target_busy', 'The slot, its task group or its Node has an Operation that must settle first.', 409);
        }
        $location = $this->settings->host($host->id);

        return [
            'task_vm_id' => $vm->id, 'node_id' => $host->id, 'host_node_id' => $host->id, 'guest_node_id' => $vm->node_id,
            'slot' => $vm->slot, 'name' => $vm->name, 'group_id' => $vm->group_id,
            'host_ssh_execution' => $this->ssh->forNode($host),
            // The two names to delete. The identity volume is named only for the end check, which proves it is still there.
            'vm' => ['project' => $location->project, 'pool' => $location->pool, 'name' => $vm->name,
                'seed_clone' => TaskVmSlot::seedClone($vm->name), 'identity_volume' => TaskVmSlot::identityVolume($vm->name)],
        ];
    }
}
