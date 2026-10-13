<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmState;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Stops a `parked` task VM and keeps its disk. The tick set the row to `parked` first, so no work is admitted
 * while the VM stops. A row that is no longer `parked` was woken meanwhile and stays running. A failure stays
 * on the row as its error, and the row stays `parked`: the next resume starts the VM whatever its power.
 */
final class ParkTaskVm implements ShouldBeUnique, ShouldQueue
{
    use TaskVmRowJob;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 600;

    /** A lost unique lock expires, so a crashed worker never blocks the row for good. */
    public int $uniqueFor = 3600;

    public function handle(TaskVmProvider $provider): void
    {
        $vm = TaskVm::query()->find($this->taskVmId);
        if ($vm?->state !== TaskVmState::Parked) {
            return;
        }

        $provider->stop($vm);
        $vm->update(['error_code' => null, 'error_message' => null]);
    }

    public function failed(Throwable $exception): void
    {
        TaskVm::query()->whereKey($this->taskVmId)->where('state', TaskVmState::Parked)->update(TaskVm::errorFrom($exception));
    }
}
