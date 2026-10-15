<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\TaskVmRoleImages;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Infrastructure\TaskVms\TaskVmStepClock;
use App\Models\TaskVm;

/** Prepares Pi on the enrolled VM of a group, then marks the task VM ready. */
final readonly class PrepareTaskVmRuntimeAction
{
    public function __construct(
        private TaskVmRuntime $runtime,
        private TaskVmStepClock $clock,
        private TaskVmRoleImages $images,
    ) {}

    public function execute(int $taskVmId): void
    {
        $vm = TaskVm::query()->find($taskVmId);
        if ($vm?->state !== TaskVmState::Provisioning) {
            return;
        }
        if ($vm->node?->status !== LifecycleStatus::Active) {
            throw new TaskVmException(TaskVmError::NotEnrolled, "Task VM [{$vm->name}] has no active Node.");
        }

        if ($vm->group_id === null) {
            throw new TaskVmException(TaskVmError::NotEnrolled, "Task VM [{$vm->name}] has no group.");
        }
        $this->clock->start($vm);
        $this->images->prepare($vm);
        $this->runtime->prepare($vm);
        // Only a row still provisioning becomes ready: a destroy or failure that ran meanwhile wins.
        $ready = TaskVm::query()->whereKey($vm->id)->where('state', TaskVmState::Provisioning)
            ->update(['state' => TaskVmState::Ready, 'ready_at' => now(), 'error_code' => null, 'error_message' => null]);
        $vm->refresh();
        if ($ready === 0) {
            $this->clock->stop();

            return;
        }
        $claimed = ($vm->claimed_at ?? $vm->created_at)?->getTimestamp();
        if ($claimed !== null) {
            $this->clock->start($vm, $claimed);
            $this->clock->step('claim-to-ready');
        }
        $this->clock->stop();
    }
}
