<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Actions\TaskVms\RefillTaskVmPoolAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Infrastructure\TaskVms\TaskVmStepClock;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Prepares Pi on the enrolled VM of a group, then marks the task VM `ready` for the next claim. A pool VM has no
 * group yet: it becomes `warm` with nothing of a group on it, and a claim that takes it queues this job again.
 */
final class PrepareTaskVmRuntime implements ShouldBeUnique, ShouldQueue
{
    use ProvisionsTaskVm;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 900;

    public function handle(TaskVmRuntime $runtime, TaskVmStepClock $clock): void
    {
        $vm = $this->provisioning();
        if (! $vm instanceof TaskVm) {
            return;
        }
        if ($vm->node?->status !== LifecycleStatus::Active) {
            throw new TaskVmException('task_vm.not_enrolled', "Task VM [{$vm->name}] has no active Node.");
        }

        if ($vm->group_id === null) {
            $this->warm($vm, $clock);

            return;
        }

        // A warm claim starts from the claim, so the first step is the wait for the worker.
        $warm = $vm->warmed_at !== null;
        $clock->start($vm, $warm ? $vm->claimed_at?->getTimestamp() : null);
        if ($warm) {
            $clock->step('queue');
        }
        $runtime->prepare($vm);
        $vm->update(['state' => TaskVmState::Ready, 'ready_at' => now(), 'error_code' => null, 'error_message' => null]);
        $claimed = ($vm->claimed_at ?? $vm->created_at)?->getTimestamp();
        if ($claimed !== null) {
            $clock->start($vm, $claimed);
            $clock->step($warm ? 'warm-claim-to-ready' : 'claim-to-ready');
        }
        $clock->stop();
    }

    /** The pool VM is enrolled and waits for a claim. The refill reads its image, which makes it claimable. */
    private function warm(TaskVm $vm, TaskVmStepClock $clock): void
    {
        $warmed = TaskVm::query()->whereKey($vm->id)->pool()->where('state', TaskVmState::Provisioning)
            ->update(['state' => TaskVmState::Warm, 'warmed_at' => now(), 'error_code' => null, 'error_message' => null]);
        if ($warmed !== 1) {
            return;
        }
        $created = $vm->created_at?->getTimestamp();
        if ($created !== null) {
            $clock->start($vm, $created);
            $clock->step('warm');
        }
        $clock->stop();
        RefillTaskVmPoolAction::warmed($vm);
        RefillTaskVmPool::dispatch();
    }
}
