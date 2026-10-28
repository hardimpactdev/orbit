<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmState;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/** Refreshes power evidence only. Never starts, stops or advances a group. */
final class ObserveParkedTaskVms implements ShouldBeUnique, ShouldQueue
{
    use TaskVmJob;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 660;

    public function __construct()
    {
        $this->onTaskVmQueue();
    }

    public function handle(TaskVmProvider $provider, TaskExecutionLock $execution): void
    {
        foreach (TaskVm::query()->where('state', TaskVmState::Parked)->whereNotNull('group_id')->get() as $candidate) {
            try {
                $execution->synchronized((int) $candidate->group_id, static function () use ($candidate, $provider): void {
                    $vm = $candidate->refresh();
                    if ($vm->state !== TaskVmState::Parked) {
                        return;
                    }
                    try {
                        $power = $provider->observe($vm);
                        $vm->update(['stop_confirmed_at' => $power !== null && $power->settled && ! $power->running ? now() : null]);
                    } catch (Throwable $exception) {
                        $vm->update(['stop_confirmed_at' => null, ...TaskVm::errorFrom($exception)]);
                        report($exception);
                    }
                }, wait: false);
            } catch (TaskWorkspaceWaiting) {
                // The admitted operation owns this row until its result is recorded.
            }
        }
    }
}
