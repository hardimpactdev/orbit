<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\TaskVms\TaskVmQueue;
use App\Domain\TaskVms\TaskVmState;
use App\Models\TaskVm;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The guard of a warm claim. The claim queues it, delayed by {@see self::DelaySeconds}, before it runs the group
 * steps itself. When a release or a crash stops `tasks:tick` during those steps, no job would ever move the row.
 * This job finds the row still `provisioning`, logs an error, and queues `PrepareTaskVmRuntime`, whose steps are
 * idempotent. A row that is `ready`, or any other state, is left alone. So is a row whose `PrepareTaskVmRuntime`
 * is still queued or running, such as the job a failed inline run queued behind a busy queue: its unique lock is
 * held, and that job finishes or fails the row.
 */
final class EnsureWarmClaimReady implements ShouldQueue
{
    use Queueable;

    /** Longer than the tick lock of 300 seconds, so a claim that still runs its steps is never overtaken. */
    public const int DelaySeconds = 330;

    public int $tries = 1;

    public function __construct(public int $taskVmId)
    {
        $this->onConnection('task-vms')->onQueue(TaskVmQueue::Vms->value);
    }

    public function handle(): void
    {
        $vm = TaskVm::query()->find($this->taskVmId);
        if (! $vm instanceof TaskVm || $vm->state !== TaskVmState::Provisioning || $vm->group_id === null) {
            return;
        }

        if (self::setupPending($vm)) {
            Log::info('A warm task VM claim waits for its queued group steps.', ['task_vm' => $vm->name, 'group_id' => $vm->group_id]);

            return;
        }

        Log::error('A warm task VM claim is not ready after its claim; the task VM worker runs its group steps.', [
            'task_vm' => $vm->name, 'group_id' => $vm->group_id, 'claimed_at' => $vm->claimed_at?->toAtomString(),
        ]);
        PrepareTaskVmRuntime::dispatch($vm->id);
    }

    /** Whether a `PrepareTaskVmRuntime` of the row is queued or running: it holds its unique lock until it ends. */
    private static function setupPending(TaskVm $vm): bool
    {
        $lock = Cache::lock(UniqueLock::getKey(new PrepareTaskVmRuntime($vm->id)));
        if (! $lock->get()) {
            return true;
        }
        $lock->release();

        return false;
    }
}
