<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubCheckFault;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubCheckSuite;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RequiredCheckState;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;

/**
 * The handoff's tests run in CI on the handoff commit ([Handoff CI](/reference/tasks#handoff-ci)). After the task
 * check passed in the workspace, Orbit commits exactly the checked tree, publishes that commit to `task-{id}`
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
        // Every handoff commit is new, so it is published on task-{id}: once, unless its pull request loses it (see missing()).
        $number = $check->pull_request_number ?? $this->publish($scheduler, $group, $task, $check, $commit);
        if ($number instanceof RequiredCheckState) {
            return $number;
        }
        $state = $this->merger->requiredCheck($group, $commit, $checkName);
        if ($state === RequiredCheckState::Unreadable) {
            $scheduler->recordCommunicationFailure($task, $group, 'Orbit could not read the check runs of '.$commit.'.');

            return $state;
        }
        $state = match ($state) {
            RequiredCheckState::Missing => $this->missing($scheduler, $group, $task, $check, $receipt, $commit, $number, $checkName),
            RequiredCheckState::Failed => $this->failed($scheduler, $group, $task, $check, $receipt, $commit, $checkName),
            default => $state,
        };
        if ($state !== RequiredCheckState::Unreadable) {
            // Only a whole read resets the count, so a read that keeps failing asks for assistance on the fifth.
            $scheduler->clearCommunicationFailures($task);
        }

        return $state;
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
                // Always a new commit, also when nothing changed, so every handoff is published and tested the same way.
                $commit = $this->signer->commit($instance, $task->title."\n\n".$receipt->body, allowEmpty: true);

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

    /**
     * Pushes the commit to `task-{id}` and records the draft pull request that runs CI on it. Returns its number,
     * `Pending` while the push runs, or `Unreadable` after a recorded failure. `$again` pushes the commit again, also
     * when Orbit pushed it before.
     */
    private function publish(TaskScheduler $scheduler, Task $group, Task $task, TaskCheck $check, string $commit, bool $again = false): int|RequiredCheckState
    {
        try {
            $number = $this->publisher->publishHandoff($group, $commit, $again);
        } catch (TaskPullRequestException $exception) {
            if ($exception->pending) {
                return RequiredCheckState::Pending;
            }
            $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

            return RequiredCheckState::Unreadable;
        }
        TaskCheck::query()->whereKey($check->id)->update(['pull_request_number' => $number, 'updated_at' => now()]);
        $check->refresh();

        return $number;
    }

    /**
     * The commit has no merge-check run yet. GitHub creates that run only after the jobs it needs finish. Orbit reads
     * the handoff's pull request and the commit's GitHub Actions check suites. Only the waits are not final:
     *
     * - A pull request that is closed, or whose head is not the commit, cannot run CI on it. Orbit publishes the
     *   commit again.
     * - A conflict with the base fails the check with the merge to do. GitHub runs no `pull_request` workflow while
     *   the pull request conflicts, and a workflow that runs on pushes does not show it.
     * - No suite yet, or a suite not completed: pending.
     * - Every suite completed: the merge-check run can no longer come. {@see GitHubCheckSuite::faultWithoutMergeCheck()}
     *   decides who answers. A fault of the change fails the check. Infrastructure asks for assistance, as a cancelled
     *   job does. An unexplained end asks for assistance that names the suites.
     */
    private function missing(TaskScheduler $scheduler, Task $group, Task $task, TaskCheck $check, TaskComment $receipt, string $commit, int $number, string $checkName): RequiredCheckState
    {
        try {
            $all = $this->merger->checkSuites($group, $commit);
            if ($all === null) {
                $scheduler->recordCommunicationFailure($task, $group, 'Orbit could not read the check suites of '.$commit.'.');

                return RequiredCheckState::Unreadable;
            }
            if ($this->merger->requiredCheck($group, $commit, $checkName) !== RequiredCheckState::Missing) {
                // The suite read began a newer snapshot, and the merge-check run is in it now: the next tick reads that run.
                return RequiredCheckState::Pending;
            }
            $pullRequest = $this->merger->handoffPullRequest($group, $commit, $number);
        } catch (TaskPullRequestException $exception) {
            $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

            return RequiredCheckState::Unreadable;
        }
        if (! $pullRequest->current) {
            TaskCheck::query()->whereKey($check->id)->update(['pull_request_number' => null, 'updated_at' => now()]);
            $check->refresh();
            $published = $this->publish($scheduler, $group, $task, $check, $commit, again: true);

            return $published instanceof RequiredCheckState ? $published : RequiredCheckState::Pending;
        }
        if ($pullRequest->conflictBase !== null) {
            $this->fail($group, $check, 'GitHub reports that the task branch conflicts with '.$pullRequest->conflictBase.', so CI cannot run on '.$commit.'. '
                .'Merge origin/'.$pullRequest->conflictBase.' into the task branch and resolve the conflicts. Do not rebase and do not force-push.');

            return RequiredCheckState::Failed;
        }
        $suites = array_values(array_filter($all, static fn (GitHubCheckSuite $suite): bool => $suite->appSlug === RequiredCheckState::CheckApp));
        if ($suites === [] || ! array_all($suites, static fn (GitHubCheckSuite $suite): bool => $suite->completed())) {
            return RequiredCheckState::Pending;
        }
        $change = array_filter($suites, static fn (GitHubCheckSuite $suite): bool => $suite->faultWithoutMergeCheck() === GitHubCheckFault::Change);
        $infrastructure = array_filter($suites, static fn (GitHubCheckSuite $suite): bool => $suite->faultWithoutMergeCheck() === GitHubCheckFault::Infrastructure);
        if ($change !== []) {
            $repository = GitHubRepository::fromOrigin((string) $group->project->repository_url);
            $this->fail($group, $check, 'GitHub Actions ended on '.$commit.' before it ran check '.$checkName.': '.self::named($change).'. '
                .'GitHub ends a workflow this way when it cannot parse or start a file under .github/workflows/. Fix the workflow, then hand off again.'
                .($repository instanceof GitHubRepository ? ' See https://github.com/'.$repository->owner.'/'.$repository->name.'/commit/'.$commit.'/checks.' : ''));

            return RequiredCheckState::Failed;
        }
        if ($infrastructure !== []) {
            $this->infrastructure($scheduler, $group, $task, $receipt, $commit, 'GitHub Actions cancelled '.self::named($infrastructure).' before check '.$checkName.' ran');

            return RequiredCheckState::Pending;
        }
        $scheduler->requestAssistance($task, $group, 'GitHub Actions completed every workflow on handoff commit '.$commit.' without check '.$checkName.': '.self::named($suites).'. '
            .'A renamed or removed job, or a narrower workflow trigger, causes this. Fix the workflow or the Project merge check, then post a resolution so the implementer hands off again.',
            handled: ['completion_handoff_comment_id' => $receipt->id]);

        return RequiredCheckState::Pending;
    }

    /** @param array<GitHubCheckSuite> $suites */
    private static function named(array $suites): string
    {
        return implode(', ', array_map(static fn (GitHubCheckSuite $suite): string => $suite->label(), $suites));
    }

    /** CI did not run to completion: the code is not at fault. Asks for assistance; no reminder, no budget charge. */
    private function infrastructure(TaskScheduler $scheduler, Task $group, Task $task, TaskComment $receipt, string $commit, string $what): void
    {
        $scheduler->requestAssistance($task, $group, 'CI did not run to completion on handoff commit '.$commit.': '.$what.'. Re-run it, then post a resolution so the implementer hands off again.',
            handled: ['completion_handoff_comment_id' => $receipt->id]);
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
            $this->infrastructure($scheduler, $group, $task, $receipt, $commit,
                implode(', ', array_map(static fn (GitHubCheckRun $run): string => $run->name.' ('.$run->conclusion.')', $failures)));

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
