<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Actions\TaskVms\FailTaskVmAction;
use App\Domain\TaskVms\TaskVmFailedException;
use App\Domain\TaskVms\TaskVmPoolBackoff;
use App\Domain\TaskVms\TaskVmState;
use App\Models\TaskVm;
use Throwable;

/**
 * The shared shape of the jobs that bring a task VM to `ready`: one job per row at a time on the
 * `task-vms` queue, a no-op once the row has left `provisioning`, and a `failed` row with the
 * error once the job has no retries left.
 */
trait ProvisionsTaskVm
{
    use TaskVmRowJob;

    /** A lost unique lock expires, so a crashed worker never blocks the row for good. */
    public int $uniqueFor = 3600;

    /** A job that outlives its timeout fails the row instead of running again after `retry_after`. */
    public bool $failOnTimeout = true;

    /** The row becomes `failed`, and its group asks for assistance, so the failure shows in `tasks:status`. A pool row has no group. */
    public function failed(Throwable $exception): void
    {
        $vm = TaskVm::query()->find($this->taskVmId);
        if (! $vm instanceof TaskVm) {
            return;
        }
        ['error_code' => $code, 'error_message' => $message] = TaskVm::errorFrom($exception);
        $failed = app(FailTaskVmAction::class)->execute($vm, [TaskVmState::Provisioning], $code, $message, TaskVmFailedException::reason($vm->name, $code, $message));
        if ($failed && $vm->group_id === null) {
            // A pool VM has no group to ask: its host waits before the next one, and the refill destroys it.
            app(TaskVmPoolBackoff::class)->failed($vm, $code, $message);
        }
    }

    /** The row while it is still `provisioning`, else null. */
    private function provisioning(): ?TaskVm
    {
        $vm = TaskVm::query()->find($this->taskVmId);

        return $vm?->state === TaskVmState::Provisioning ? $vm : null;
    }
}
