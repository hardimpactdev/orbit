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
 * ({@see TaskVmTopologyAdmission}). It belongs to one import, the row's `topology_run` while the row is `importing`,
 * and does nothing for another. A failure stays on the row of its own import, and admission asks for assistance.
 */
final class TransferTaskVmRoleImages implements ShouldBeUnique, ShouldQueue
{
    use TaskVmJob;

    /** One try: a retry would hold the task-vms worker for another copy, and the next import retries anyway. */
    public int $tries = 1;

    /** Longer than the transfer's own 1200-second limit. */
    public int $timeout = 1500;

    /** A lost unique lock expires, so a crashed worker never blocks the row for good. */
    public int $uniqueFor = 3600;

    public function __construct(public int $taskVmId, public string $run)
    {
        $this->onTaskVmQueue();
    }

    /** Unique per row: one copy into a VM at a time. */
    public function uniqueId(): string
    {
        return (string) $this->taskVmId;
    }

    public function handle(TaskVmRoleImages $images): void
    {
        $vm = TaskVm::query()->find($this->taskVmId);
        if ($vm?->topology_state !== TaskVmTopologyState::Importing || $vm->topology_run !== $this->run) {
            return;
        }

        $images->prepare($vm);
    }

    public function failed(Throwable $exception): void
    {
        TaskVm::query()->whereKey($this->taskVmId)->where('topology_state', TaskVmTopologyState::Importing)->where('topology_run', $this->run)
            ->update(['topology_error' => $exception->getMessage()]);
    }
}
