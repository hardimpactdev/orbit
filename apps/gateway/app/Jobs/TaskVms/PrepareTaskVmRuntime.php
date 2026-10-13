<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Actions\TaskVms\PrepareTaskVmRuntimeAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Prepares Pi on the enrolled VM of a group, then marks the task VM `ready` for the next claim
 * ({@see PrepareTaskVmRuntimeAction}). A pool VM has no group yet: it becomes `warm` with nothing of a group on it.
 */
final class PrepareTaskVmRuntime implements ShouldBeUnique, ShouldQueue
{
    use ProvisionsTaskVm;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 900;

    public function handle(PrepareTaskVmRuntimeAction $prepare): void
    {
        $prepare->execute($this->taskVmId);
    }
}
