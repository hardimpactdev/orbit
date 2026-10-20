<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\RequiredCheckState;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;

/**
 * The handoff's tests run in CI on the handoff commit ([Handoff CI](/reference/tasks#handoff-ci)). After the task
 * check passed in the workspace, Orbit commits exactly the checked tree, publishes that commit once to `task-{id}`
 * with a draft pull request, and reads the Project's merge check on exactly that commit. Nothing waits in a
 * request: each tick reads the result again, through the merger's shared check-run read.
 */
final readonly class TaskHandoffCi
{
    /** The `failed_step` of a handoff check whose tests failed in CI, or that CI could not run on. */
    public const string FailedStep = 'ci';

    public function __construct(
        private TaskWorkspaceSigner $signer,
        private TaskCheckRunner $checks,
        private TaskPullRequestPublisher $publisher,
        private TaskPullRequestMerger $merger,
        private TaskPullRequestCheckOutput $checkOutput,
        private TaskBroadcasts $broadcasts,
    ) {}

    /**
     * The merge check's state on the handoff commit. `Failed` means this call stored the failure on the check,
     * as `failed_step` `ci` with the evidence as its output. Every other state but `Passed` means the handoff waits.
     */
    public function test(TaskScheduler $scheduler, Task $group, Task $task, TaskCheck $check, TaskComment $receipt, ?int $threadId, string $checkName): RequiredCheckState
    {
        $commit = $check->commit_sha ?? $this->commit($scheduler, $group, $task, $check, $receipt, $threadId);
        if ($commit === null || $check->status !== TaskCheckStatus::Passed) {
            return $check->status === TaskCheckStatus::Failed ? RequiredCheckState::Failed : RequiredCheckState::Pending;
        }
        // The group's start commit is on the default branch: its run comes from main's CI, and a branch equal to its
        // base cannot open a pull request. Every other commit is published once.
        if ($check->pull_request_number === null && $commit !== TaskReviewBase::groupStartCommit($group)
            && ! $this->publish($scheduler, $group, $task, $check, $commit)) {
            return RequiredCheckState::Pending;
        }
        $state = $this->merger->requiredCheck($group, $commit, $checkName);
        if ($state === RequiredCheckState::Unreadable) {
            $scheduler->recordCommunicationFailure($task, $group, 'Orbit could not read the check runs of '.$commit.'.');

            return $state;
        }
        $scheduler->clearCommunicationFailures($task);

        return match ($state) {
            // GitHub creates the merge check's run only after the jobs it needs finish, so a missing run is pending.
            RequiredCheckState::Missing => $this->conflicting($scheduler, $group, $task, $check, $commit) ? RequiredCheckState::Failed : RequiredCheckState::Pending,
            RequiredCheckState::Failed => $this->failed($scheduler, $group, $task, $check, $receipt, $commit, $checkName),
            default => $state,
        };
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
                $commit = $this->signer->commit($instance, $task->title."\n\n".$receipt->body);

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

    /** Pushes the commit to `task-{id}` and records the draft pull request that runs CI on it. Whether it is published. */
    private function publish(TaskScheduler $scheduler, Task $group, Task $task, TaskCheck $check, string $commit): bool
    {
        try {
            $number = $this->publisher->publishHandoff($group, $commit);
        } catch (TaskPullRequestException $exception) {
            if (! $exception->pending) {
                $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());
            }

            return false;
        }
        TaskCheck::query()->whereKey($check->id)->update(['pull_request_number' => $number, 'updated_at' => now()]);
        $check->refresh();

        return true;
    }

    /**
     * Whether GitHub keeps CI from running at all: no run exists on the commit, and its pull request conflicts with
     * its base. GitHub runs no `pull_request` workflow then. The check fails with the merge to do.
     */
    private function conflicting(TaskScheduler $scheduler, Task $group, Task $task, TaskCheck $check, string $commit): bool
    {
        $number = $check->pull_request_number;
        try {
            if ($number === null || $this->merger->checkRuns($group, $commit) !== []) {
                return false;
            }
            $pullRequest = $this->merger->pullRequest($group, $number);
        } catch (TaskPullRequestException $exception) {
            $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

            return false;
        }
        if ($pullRequest->state !== GitHubPullRequestState::Open || ! $pullRequest->conflicts()) {
            return false;
        }
        $base = $pullRequest->baseRef ?? 'the base branch';
        $this->fail($group, $check, 'GitHub reports that the task branch conflicts with '.$base.', so CI cannot run on '.$commit.'. '
            .'Merge origin/'.$base.' into the task branch and resolve the conflicts. Do not rebase and do not force-push.');

        return true;
    }

    /**
     * A failed merge check. Failed jobs go to the implementer as a failed check. When every failed job is
     * infrastructure (cancelled, or could not start), as settling judges it too, the code is not at fault: Orbit asks
     * for assistance, does not charge the implementer's failed-check budget, and leaves the check passed.
     */
    private function failed(TaskScheduler $scheduler, Task $group, Task $task, TaskCheck $check, TaskComment $receipt, string $commit, string $checkName): RequiredCheckState
    {
        try {
            $failures = TaskPullRequestCheck::failures($this->merger->checkRuns($group, $commit) ?? []);
        } catch (TaskPullRequestException $exception) {
            $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

            return RequiredCheckState::Unreadable;
        }
        $genuine = array_values(array_filter($failures, static fn (GitHubCheckRun $run): bool => ! $run->infrastructure()));
        if ($failures !== [] && $genuine === []) {
            $runs = implode(', ', array_map(static fn (GitHubCheckRun $run): string => $run->name.' ('.$run->conclusion.')', $failures));
            $scheduler->requestAssistance($task, $group, 'CI did not run to completion on handoff commit '.$commit.': '.$runs.'. Re-run the jobs, then post a resolution so the implementer hands off again.',
                handled: ['completion_handoff_comment_id' => $receipt->id]);

            return RequiredCheckState::Pending;
        }
        $this->fail($group, $check, $genuine === []
            ? 'Check '.$checkName.' did not pass on '.$commit.', or a run of that name came from an App other than github-actions.'
            : implode("\n\n", array_map(function (GitHubCheckRun $run) use ($group): string {
                $failure = TaskPullRequestCheck::fromRun($run);

                return 'Check '.$failure->name.' failed'.($failure->url !== null ? ' ('.$failure->url.')' : '').".\n\n".$this->checkOutput->read($group, $failure);
            }, $genuine)));

        return RequiredCheckState::Failed;
    }

    /**
     * HEAD left the commit that CI passed before the approval, so that result no longer covers the work. The check
     * fails, and the reopened handoff runs the check and CI on the current HEAD.
     */
    public function moved(Task $group, TaskCheck $check, string $head): void
    {
        $this->fail($group, $check, 'The workspace HEAD is '.$head.', not the commit '.$check->commit_sha.' that CI passed on. '
            .'Hand off again, so the check and CI run on the current HEAD.');
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
