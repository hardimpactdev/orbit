<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Actions\TaskVms\FailTaskVmAction;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmFailedException;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Starts a `parked` task VM and sets it `ready` once its Node agent and Pi answer, so the group's waiting
 * work can go on. It checks every 2 seconds for up to 3 minutes in one run, then runs again 15 seconds later.
 * After the third run the row becomes `failed` with `task_vm.resume_timeout`, and the group asks for assistance.
 */
final class ResumeTaskVm implements ShouldBeUnique, ShouldQueue
{
    use TaskVmRowJob;

    public const int Runs = 3;

    public const int RunSeconds = 180;

    /** Every run counts as a try, the 15-second waits included. */
    public int $tries = self::Runs;

    public int $maxExceptions = 3;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    /** A lost unique lock expires, so a crashed worker never blocks the row for good. */
    public int $uniqueFor = 3600;

    public function handle(TaskVmProvider $provider, TaskVmRuntime $runtime): void
    {
        $vm = TaskVm::query()->find($this->taskVmId);
        if ($vm?->state !== TaskVmState::Parked) {
            return;
        }

        $provider->start($vm);
        $deadline = now()->addSeconds(self::RunSeconds);
        while (true) {
            if ($runtime->responds($vm)) {
                TaskVm::query()->whereKey($vm->id)->where('state', TaskVmState::Parked)
                    ->update(['state' => TaskVmState::Ready, 'ready_at' => now(), 'error_code' => null, 'error_message' => null, ...TaskVm::ForgetTopology]);

                return;
            }
            if (now()->greaterThanOrEqualTo($deadline)) {
                break;
            }
            Sleep::for(2)->seconds();
        }

        if ($this->attempts() >= self::Runs) {
            throw new TaskVmException(TaskVmError::ResumeTimeout, "Task VM [{$vm->name}] did not answer after its start: its Node agent or Pi is not ready.", 504);
        }
        $this->release(15);
    }

    /** Only a row that is still `parked` fails, so a later destroy or resume is never overwritten. */
    public function failed(Throwable $exception): void
    {
        $vm = TaskVm::query()->find($this->taskVmId);
        if ($vm instanceof TaskVm) {
            ['error_code' => $code, 'error_message' => $message] = TaskVm::errorFrom($exception);
            app(FailTaskVmAction::class)->execute($vm, [TaskVmState::Parked], $code, $message, TaskVmFailedException::stoppedReason($code, $message));
        }
    }
}
