<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\TaskVms\TaskVmTopologyState;
use App\Infrastructure\TaskVms\TaskVmRoleImages;
use App\Infrastructure\TaskVms\TaskVmTopologyAdmission;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Transfers the recipe base image into a task VM whose topology waits for it, so no tick waits on the copy
 * ({@see TaskVmTopologyAdmission}). It runs only while the row is `importing`. After the last try the error stays on
 * the row, and admission asks for assistance with it.
 */
final class TransferTaskVmRoleImages implements ShouldBeUnique, ShouldQueue
{
    use TaskVmRowJob;

    public int $tries = 2;

    public int $backoff = 30;

    /** Longer than the transfer's own 1200-second limit. */
    public int $timeout = 1500;

    /** A lost unique lock expires, so a crashed worker never blocks the row for good. */
    public int $uniqueFor = 3600;

    public function handle(TaskVmRoleImages $images): void
    {
        $vm = TaskVm::query()->find($this->taskVmId);
        if ($vm?->topology_state !== TaskVmTopologyState::Importing) {
            return;
        }

        $images->prepare($vm);
    }

    public function failed(Throwable $exception): void
    {
        TaskVm::query()->whereKey($this->taskVmId)->where('topology_state', TaskVmTopologyState::Importing)
            ->update(['topology_error' => $exception->getMessage()]);
    }
}
