<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmPoolBackoff;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Infrastructure\TaskVms\TaskVmStepClock;
use App\Jobs\TaskVms\PrepareTaskVmRuntime;
use App\Jobs\TaskVms\RefillTaskVmPool;
use App\Models\TaskVm;

/**
 * Prepares Pi on the enrolled VM of a group, then marks the task VM `ready`. A pool VM has no group yet: it becomes
 * `warm` with nothing of a group on it. The job {@see PrepareTaskVmRuntime} runs it, and a warm claim runs it inline,
 * which keeps every step short ({@see TaskVmRuntime::prepare()}). A row that has left `provisioning` is left alone.
 */
final readonly class PrepareTaskVmRuntimeAction
{
    public function __construct(
        private TaskVmRuntime $runtime,
        private TaskVmStepClock $clock,
        private TaskVmPoolBackoff $backoff,
    ) {}

    public function execute(int $taskVmId, bool $inline = false): void
    {
        $vm = TaskVm::query()->find($taskVmId);
        if ($vm?->state !== TaskVmState::Provisioning) {
            return;
        }
        if ($vm->node?->status !== LifecycleStatus::Active) {
            throw new TaskVmException(TaskVmError::NotEnrolled, "Task VM [{$vm->name}] has no active Node.");
        }

        if ($vm->group_id === null) {
            $this->warm($vm);

            return;
        }

        // A warm claim starts from the claim, so the first step is the wait for the worker.
        $warm = $vm->warmed_at !== null;
        $this->clock->start($vm, $warm ? $vm->claimed_at?->getTimestamp() : null);
        if ($warm) {
            $this->clock->step('queue');
        }
        $this->runtime->prepare($vm, $inline);
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
            $this->clock->step($warm ? 'warm-claim-to-ready' : 'claim-to-ready');
        }
        $this->clock->stop();
    }

    /** The pool VM is enrolled and waits for a claim. The refill reads its image, which makes it claimable. */
    private function warm(TaskVm $vm): void
    {
        $warmed = TaskVm::query()->whereKey($vm->id)->pool()->where('state', TaskVmState::Provisioning)
            ->update(['state' => TaskVmState::Warm, 'warmed_at' => now(), 'error_code' => null, 'error_message' => null]);
        if ($warmed !== 1) {
            return;
        }
        $created = $vm->created_at?->getTimestamp();
        if ($created !== null) {
            $this->clock->start($vm, $created);
            $this->clock->step('warm');
        }
        $this->clock->stop();
        $this->backoff->warmed($vm);
        RefillTaskVmPool::dispatch();
    }
}
