<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmQueue;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Starts a `parked` task VM and sets it `ready` once its Node agent and Pi answer, so the group's waiting
 * work can go on. It checks every 2 seconds for up to 3 minutes in one run, then runs again 15 seconds later.
 * After the third run the row becomes `failed` with `task_vm.resume_timeout`, and the group asks for assistance.
 */
final class ResumeTaskVm implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int Runs = 3;

    public const int RunSeconds = 180;

    /** Every run counts as a try, the 15-second waits included. */
    public int $tries = self::Runs;

    public int $maxExceptions = 3;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    /** A lost unique lock expires, so a crashed worker never blocks the row for good. */
    public int $uniqueFor = 3600;

    public function __construct(public int $taskVmId)
    {
        $this->onConnection('task-vms')->onQueue(TaskVmQueue::Vms->value);
    }

    public function uniqueId(): string
    {
        return (string) $this->taskVmId;
    }

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
                    ->update(['state' => TaskVmState::Ready, 'error_code' => null, 'error_message' => null]);

                return;
            }
            if (now()->greaterThanOrEqualTo($deadline)) {
                break;
            }
            Sleep::for(2)->seconds();
        }

        if ($this->attempts() >= self::Runs) {
            throw new TaskVmException('task_vm.resume_timeout', "Task VM [{$vm->name}] did not answer after its start: its Node agent or Pi is not ready.", 504);
        }
        $this->release(15);
    }

    /** Only a row that is still `parked` fails, so a later destroy or resume is never overwritten. */
    public function failed(Throwable $exception): void
    {
        $code = TaskVmException::codeOf($exception);
        $message = mb_substr($exception->getMessage(), 0, 2000);
        $failed = TaskVm::query()->whereKey($this->taskVmId)->where('state', TaskVmState::Parked)
            ->update(['state' => TaskVmState::Failed, 'error_code' => $code, 'error_message' => $message]);
        $group = TaskVm::query()->find($this->taskVmId)?->group;
        if ($failed === 1 && $group instanceof Task) {
            TaskAssistance::apply($group, AssistanceKind::Failure, null, ProvisionTaskVm::FailedReasonPrefix."{$code}: {$message} Cancel or complete the group to destroy the VM.", replaceFailure: true);
        }
    }
}
