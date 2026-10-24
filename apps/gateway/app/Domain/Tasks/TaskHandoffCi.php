<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\RequiredCheckState;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;

/**
 * The handoff's tests run in CI on the handoff commit ([Handoff CI](/reference/tasks#handoff-ci)). After the task
 * check passed in the workspace, Orbit commits exactly the checked tree and pushes that commit once to its branch
 * `task-{id}-handoff`. The push starts CI on exactly that commit; no pull request takes part. Nothing waits in a
 * request: each tick reads the commit's checks again, through the merger's shared read.
 */
final readonly class TaskHandoffCi
{
    /** The `failed_step` of a handoff check whose tests failed in CI. */
    public const string FailedStep = 'ci';

    /** How long after the push a commit without a GitHub Actions suite waits before it asks for assistance. */
    public const int NoSuiteSeconds = 600;

    public function __construct(
        private TaskWorkspaceSigner $signer,
        private TaskCheckRunner $checks,
        private TaskHandoffCiChecks $hosted,
        private TaskBroadcasts $broadcasts,
    ) {}

    /**
     * The merge check's state on the handoff commit. `Failed` means this call stored the failure on the check,
     * as `failed_step` `ci` with the evidence as its output. Every other state but `Passed` means the handoff waits.
     */
    public function test(TaskScheduler $scheduler, Task $group, Task $task, TaskCheck $check, TaskComment $receipt, ?int $threadId, string $checkName): RequiredCheckState
    {
        TaskGroupGuard::requireTick($group);
        $commit = $check->commit_sha ?? $this->commit($scheduler, $group, $task, $check, $receipt, $threadId);
        if ($commit === null || $check->status !== TaskCheckStatus::Passed) {
            return $check->status === TaskCheckStatus::Failed ? RequiredCheckState::Failed : RequiredCheckState::Pending;
        }
        try {
            $this->hosted->push($group, $check, $commit);
        } catch (TaskPullRequestException $exception) {
            if ($exception->pending) {
                return RequiredCheckState::Pending;
            }
            $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

            return RequiredCheckState::Unreadable;
        }
        $reading = $this->hosted->read($group, $check, $commit, $checkName);
        if ($reading->state === RequiredCheckState::Unreadable) {
            $scheduler->recordCommunicationFailure($task, $group, $reading->output);

            return RequiredCheckState::Unreadable;
        }
        $scheduler->clearCommunicationFailures($task);
        if ($reading->fault === 'change') {
            $this->fail($group, $check, $reading->output);

            return RequiredCheckState::Failed;
        }
        if ($reading->fault !== null) {
            $this->assistance($scheduler, $group, $task, $receipt, $reading->output);
        }

        return $reading->state === RequiredCheckState::Passed ? RequiredCheckState::Passed : RequiredCheckState::Pending;
    }

    /**
     * Commits the checked tree as the subtask's work, under the receipt's workspace fence, and records the commit on
     * the check. A commit that does not hold exactly the tree the check passed on fails the check.
     */
    private function commit(TaskScheduler $scheduler, Task $group, Task $task, TaskCheck $check, TaskComment $receipt, ?int $threadId): ?string
    {
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            $scheduler->recordCommunicationFailure($task, $group, 'The task workspace is unavailable.');

            return null;
        }
        try {
            $committed = app(TaskWorkspaceLease::class)->withReceipt($group, $task->id, $threadId, function () use ($instance, $task, $receipt): ?TaskWorkspaceSnapshot {
                // Always a new commit, also when nothing changed, so every handoff is pushed and tested the same way.
                $commit = $this->signer->commit($instance, TaskHandoffCiChecks::message($task, $receipt->body), allowEmpty: true);

                return $commit === null ? null : $this->checks->snapshot($instance);
            });
        } catch (TaskCheckException $exception) {
            $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

            return null;
        }
        if (! $committed instanceof TaskWorkspaceSnapshot) {
            $scheduler->recordCommunicationFailure($task, $group, 'Orbit could not commit the handoff for CI.');

            return null;
        }
        if (TaskCheck::query()->whereKey($check->id)->whereNull('commit_sha')->update(['commit_sha' => $committed->head, 'updated_at' => now()]) === 1) {
            $this->broadcasts->groupChanged($group->id);
        }
        $check->refresh();
        if ($committed->commitTree === null || $committed->commitTree !== $check->tree_after) {
            $this->fail($group, $check, 'Orbit committed the workspace as '.$committed->head.', but that commit holds tree '.($committed->commitTree ?? 'unknown')
                .', not tree '.($check->tree_after ?? 'unknown').' that the check passed on. The workspace changed after the check. Hand off again, so the check runs on the committed tree.');
        }

        return $check->commit_sha;
    }

    /** Orbit cannot charge the result to the implementer. Someone must look; no reminder, no budget charge. */
    private function assistance(TaskScheduler $scheduler, Task $group, Task $task, TaskComment $receipt, string $reason): void
    {
        $scheduler->requestAssistance($task, $group, $reason.' Then post a resolution so the implementer hands off again.',
            handled: ['completion_handoff_comment_id' => $receipt->id]);
    }

    /** Records the CI result on the handoff check, which until now held only the passed workspace check. */
    private function fail(Task $group, TaskCheck $check, string $evidence): void
    {
        if (TaskCheck::query()->whereKey($check->id)->where('status', TaskCheckStatus::Passed->value)
            ->update(['status' => TaskCheckStatus::Failed->value, 'failed_step' => self::FailedStep, 'output' => $evidence, 'updated_at' => now()]) === 1) {
            $this->broadcasts->groupChanged($group->id);
        }
        $check->refresh();
    }
}
