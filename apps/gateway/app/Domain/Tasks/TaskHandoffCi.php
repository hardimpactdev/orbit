<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

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
        // Every handoff commit is new, so it is published once, on task-{id}.
        if ($check->pull_request_number === null && ! $this->publish($scheduler, $group, $task, $check, $commit)) {
            return RequiredCheckState::Pending;
        }
        $state = $this->merger->requiredCheck($group, $commit, $checkName);
        if ($state === RequiredCheckState::Unreadable) {
            $scheduler->recordCommunicationFailure($task, $group, 'Orbit could not read the check runs of '.$commit.'.');

            return $state;
        }
        $state = match ($state) {
            RequiredCheckState::Missing => $this->missing($scheduler, $group, $task, $check, $receipt, $commit, $checkName),
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
     * The commit has no merge-check run yet. GitHub creates that run only after the jobs it needs finish. The commit's
     * GitHub Actions check suites decide whether one still can come:
     *
     * - No suite: pending. A pull request that conflicts with its base gets no `pull_request` workflow at all, so a
     *   conflict fails the check with the merge to do.
     * - A suite not completed: pending.
     * - Every suite completed, one with `startup_failure` or `failure`: the workflow ended before the merge check, as
     *   for a workflow file GitHub cannot start. The implementer gets a failed check.
     * - Every suite completed, one cancelled: infrastructure, as for a cancelled job.
     */
    private function missing(TaskScheduler $scheduler, Task $group, Task $task, TaskCheck $check, TaskComment $receipt, string $commit, string $checkName): RequiredCheckState
    {
        try {
            $all = $this->merger->checkSuites($group, $commit);
            if ($all === null) {
                $scheduler->recordCommunicationFailure($task, $group, 'Orbit could not read the check suites of '.$commit.'.');

                return RequiredCheckState::Unreadable;
            }
            $suites = array_values(array_filter($all, static fn (GitHubCheckSuite $suite): bool => $suite->appSlug === RequiredCheckState::CheckApp));
            $conflict = $suites === [] && $check->pull_request_number !== null
                ? $this->merger->conflictBase($group, $commit, $check->pull_request_number) : null;
        } catch (TaskPullRequestException $exception) {
            $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

            return RequiredCheckState::Unreadable;
        }
        if ($conflict !== null) {
            $this->fail($group, $check, 'GitHub reports that the task branch conflicts with '.$conflict.', so CI cannot run on '.$commit.'. '
                .'Merge origin/'.$conflict.' into the task branch and resolve the conflicts. Do not rebase and do not force-push.');

            return RequiredCheckState::Failed;
        }
        if ($suites === [] || ! array_all($suites, static fn (GitHubCheckSuite $suite): bool => $suite->completed())) {
            return RequiredCheckState::Pending;
        }
        $conclusions = array_values(array_unique(array_map(static fn (GitHubCheckSuite $suite): string => (string) $suite->conclusion, $suites)));
        $ended = array_values(array_intersect($conclusions, ['startup_failure', 'failure']));
        if ($ended !== []) {
            $repository = GitHubRepository::fromOrigin((string) $group->project->repository_url);
            $this->fail($group, $check, 'GitHub Actions ended on '.$commit.' with '.implode(' and ', $ended).' before it ran check '.$checkName.'. '
                .'A workflow that GitHub cannot start, such as a broken file under .github/workflows/, causes this.'
                .($repository instanceof GitHubRepository ? ' See https://github.com/'.$repository->owner.'/'.$repository->name.'/commit/'.$commit.'/checks.' : ''));

            return RequiredCheckState::Failed;
        }
        if (in_array('cancelled', $conclusions, true)) {
            $this->infrastructure($scheduler, $group, $task, $receipt, $commit, 'GitHub Actions cancelled its workflow before check '.$checkName.' ran');
        }

        return RequiredCheckState::Pending;
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
