<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Actions\TaskVms\FailTaskVmAction;
use App\Domain\Tasks\TaskWorkspaceOwnership;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmFailedException;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Models\TaskVm;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Throwable;

/**
 * Starts a `parked` task VM and sets it `ready` once its Node agent and Pi answer, so the group's waiting
 * work can go on. Each attempt checks once and releases for two seconds until its fixed nine-minute deadline.
 * Expiry fails the row with `task_vm.resume_timeout`, and the group asks for assistance.
 */
final class ResumeTaskVm implements ShouldBeUnique, ShouldQueue
{
    use TaskVmRowJob {
        __construct as private initializeRow;
    }

    public const int DeadlineSeconds = 540;

    /** Null only in payloads queued by the previous release. */
    public ?DateTimeImmutable $deadline = null;

    public int $tries = 0;

    public function __construct(int $taskVmId)
    {
        $this->initializeRow($taskVmId);
        $this->deadline = DateTimeImmutable::createFromInterface(now()->addSeconds(self::DeadlineSeconds));
    }

    public function retryUntil(): DateTimeImmutable
    {
        // An old payload's creation time is stable across releases; never extend its budget on a retry.
        return $this->deadline ?? new DateTimeImmutable('@'.((int) ($this->job?->payload()['createdAt'] ?? now()->timestamp) + self::DeadlineSeconds));
    }

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

        TaskWorkspaceOwnership::requireVmWorkspaces($vm);

        if (now()->greaterThanOrEqualTo($this->retryUntil())) {
            $this->fail($this->timeoutException($vm));

            return;
        }

        $provider->start($vm);
        if ($runtime->responds($vm)) {
            TaskVm::query()->whereKey($vm->id)->where('state', TaskVmState::Parked)
                ->update(['state' => TaskVmState::Ready, 'ready_at' => now(), 'error_code' => null, 'error_message' => null, ...TaskVm::ForgetTopology]);

            return;
        }
        $this->release(2);
    }

    private function timeoutException(TaskVm $vm): TaskVmException
    {
        return new TaskVmException(TaskVmError::ResumeTimeout, "Task VM [{$vm->name}] did not answer after its start: its Node agent or Pi is not ready.", 504);
    }

    /** Only a row that is still `parked` fails, so a later destroy or resume is never overwritten. */
    public function failed(Throwable $exception): void
    {
        $vm = TaskVm::query()->find($this->taskVmId);
        if ($vm instanceof TaskVm) {
            if ($exception instanceof MaxAttemptsExceededException || now()->greaterThanOrEqualTo($this->retryUntil())) {
                $exception = $this->timeoutException($vm);
            }
            ['error_code' => $code, 'error_message' => $message] = TaskVm::errorFrom($exception);
            app(FailTaskVmAction::class)->execute($vm, [TaskVmState::Parked], $code, $message, TaskVmFailedException::stoppedReason($code, $message));
        }
    }
}
