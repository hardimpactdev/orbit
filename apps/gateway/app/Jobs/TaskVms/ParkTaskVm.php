<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Actions\TaskVms\StopTaskVmAction;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stops a `parked` task VM and keeps its disk. The tick set the row to `parked` first, so no work is admitted
 * while the VM stops. A row that is no longer `parked` was woken meanwhile and stays running. A failure stays
 * on the row as its error. After the last try the row returns to `ready` for the next idle tick and a warning names the error.
 * Group assistance stays unchanged, so later work can resume the VM whatever its power.
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

        app(TaskExecutionLock::class)->synchronized((int) $vm->group_id, static function () use ($vm, $provider): void {
            $vm->refresh();
            if ($vm->state !== TaskVmState::Parked) {
                return;
            }
            if (Operation::query()->where('target_type', 'task_group')->where('target_id', $vm->group_id)
                ->whereNotIn('status', ['succeeded', 'failed', 'cancelled'])->exists()) {
                $vm->update(['state' => TaskVmState::Ready]);

                return;
            }
            new StopTaskVmAction($provider)->execute($vm);
        });
    }

    public function failed(Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $vm = TaskVm::query()->whereKey($this->taskVmId)->where('state', TaskVmState::Parked)->lockForUpdate()->first();
            if (! $vm instanceof TaskVm) {
                return;
            }
            $error = TaskVm::errorFrom($exception);
            $vm->update(['state' => TaskVmState::Ready, ...$error]);
            Log::warning('A task VM could not park; later work can still resume it.', [
                'task_vm_id' => $vm->id, 'task_vm' => $vm->name, 'group_id' => $vm->group_id, ...$error,
            ]);
        });
    }
}
