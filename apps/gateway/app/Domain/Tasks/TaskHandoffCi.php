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

    /** GitHub skips push workflows for a commit message with one of these, so the handoff message never holds one. */
    private const string SkipDirectives = '/\[(?:skip ci|ci skip|no ci|skip actions|actions skip)\]|^[ \t]*skip-checks[ \t]*:[ \t]*true[ \t]*$/im';

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
        try {
            // The task VM row records the push, so later ticks push nothing again.
            $this->publisher->pushHandoff($group, $commit);
            if ($check->pushed_at === null) {
                TaskCheck::query()->whereKey($check->id)->whereNull('pushed_at')->update(['pushed_at' => now(), 'updated_at' => now()]);
                $check->refresh();
            }
            $checks = $this->merger->commitChecks($group, $commit);
        } catch (TaskPullRequestException $exception) {
            if ($exception->pending) {
                return RequiredCheckState::Pending;
            }
            $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

            return RequiredCheckState::Unreadable;
        }
        if ($checks === null) {
            $scheduler->recordCommunicationFailure($task, $group, 'Orbit could not read the checks of '.$commit.': the GitHub App has no checks: read permission.');

            return RequiredCheckState::Unreadable;
        }
        $scheduler->clearCommunicationFailures($task);
        $state = RequiredCheckState::of($checks['runs'], $commit, $checkName);

        return match ($state) {
            RequiredCheckState::Missing => $this->missing($scheduler, $group, $task, $check, $receipt, $commit, $checkName, $checks['runs'], $checks['suites']),
            RequiredCheckState::Failed => $this->failed($scheduler, $group, $task, $check, $receipt, $commit, $checkName, $checks['runs']),
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
                // Always a new commit, also when nothing changed, so every handoff is pushed and tested the same way.
                $commit = $this->signer->commit($instance, self::message($task, $receipt), allowEmpty: true);

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
     * The commit's current suites have no merge-check run yet. The shared read removes superseded suites and their
     * jobs. No suite yet, or one not completed: pending. No suite {@see NoSuiteSeconds} after the push asks for
     * assistance once, because nothing in Orbit can start CI. Once every current suite completed without the merge
     * check, {@see GitHubCheckSuite::faultWithoutMergeCheck()} decides who answers.
     *
     * @param  list<GitHubCheckRun>  $runs
     * @param  list<GitHubCheckSuite>  $suites
     */
    private function missing(TaskScheduler $scheduler, Task $group, Task $task, TaskCheck $check, TaskComment $receipt, string $commit, string $checkName, array $runs, array $suites): RequiredCheckState
    {
        $suites = array_values(array_filter($suites, static fn (GitHubCheckSuite $suite): bool => $suite->appSlug === RequiredCheckState::CheckApp));
        if ($suites === []) {
            if ($check->pushed_at?->isBefore(now()->subSeconds(self::NoSuiteSeconds)) === true) {
                $this->assistance($scheduler, $group, $task, $receipt, 'GitHub Actions started no workflow on handoff commit '.$commit.' in the '.(self::NoSuiteSeconds / 60)
                    .' minutes since Orbit pushed it to '.TaskRemoteBranch::handoff($group).'. A ci.yml on the task branch without the task-*-handoff push trigger, '
                    .'such as one from before that trigger existed, or a skipped workflow causes this. Merge main into the task branch.');
            }

            return RequiredCheckState::Pending;
        }
        if (! array_all($suites, static fn (GitHubCheckSuite $suite): bool => $suite->completed())) {
            return RequiredCheckState::Pending;
        }
        $change = array_filter($suites, static fn (GitHubCheckSuite $suite): bool => $suite->faultWithoutMergeCheck() === GitHubCheckFault::Change);
        $infrastructure = array_filter($suites, static fn (GitHubCheckSuite $suite): bool => $suite->faultWithoutMergeCheck() === GitHubCheckFault::Infrastructure);
        if ($change !== []) {
            $failed = self::changeFailures($runs);
            $repository = GitHubRepository::fromOrigin((string) $group->project->repository_url);
            $this->fail($group, $check, 'GitHub Actions ended on '.$commit.' without check '.$checkName.': '.self::named($change).'.'.($failed !== []
                ? "\n\n".$this->evidence($group, $failed)
                : ' No failed job explains it: GitHub ends a workflow this way when it cannot parse or start a file under .github/workflows/. Fix the workflow, then hand off again.'
                    .($repository instanceof GitHubRepository ? ' See https://github.com/'.$repository->owner.'/'.$repository->name.'/commit/'.$commit.'/checks.' : '')));

            return RequiredCheckState::Failed;
        }
        if ($infrastructure !== []) {
            $this->infrastructure($scheduler, $group, $task, $receipt, $commit, 'GitHub Actions cancelled '.self::named($infrastructure).' before check '.$checkName.' ran');

            return RequiredCheckState::Pending;
        }
        $this->assistance($scheduler, $group, $task, $receipt, 'GitHub Actions completed every workflow on handoff commit '.$commit.' without check '.$checkName.': '.self::named($suites)
            .'. A renamed or removed job, or a workflow that no longer runs on pushes to task-*-handoff branches, causes this. Fix the workflow or the Project merge check.');

        return RequiredCheckState::Pending;
    }

    /**
     * The merge check did not pass. {@see GitHubCheckRun::fault()} judges each run. Failed jobs of the change go to the
     * implementer as a failed check. When only infrastructure failed, such as a cancelled job, Orbit asks for
     * assistance. When no run failed, such as a skipped, neutral, or stale merge-check run, or a run of that name from
     * another App, nothing is charged to the implementer either: Orbit asks for assistance that names the runs.
     *
     * @param  list<GitHubCheckRun>  $runs
     */
    private function failed(TaskScheduler $scheduler, Task $group, Task $task, TaskCheck $check, TaskComment $receipt, string $commit, string $checkName, array $runs): RequiredCheckState
    {
        $change = self::changeFailures($runs);
        if ($change !== []) {
            $this->fail($group, $check, $this->evidence($group, $change));

            return RequiredCheckState::Failed;
        }
        $failures = TaskPullRequestCheck::failures($runs);
        if ($failures !== []) {
            $this->infrastructure($scheduler, $group, $task, $receipt, $commit,
                implode(', ', array_map(static fn (GitHubCheckRun $run): string => $run->name.' ('.$run->conclusion.')', $failures)));

            return RequiredCheckState::Pending;
        }
        $named = array_filter($runs, static fn (GitHubCheckRun $run): bool => $run->name === $checkName);
        $this->assistance($scheduler, $group, $task, $receipt, 'Check '.$checkName.' did not pass on handoff commit '.$commit.', but no job failed: '
            .implode(', ', array_map(static fn (GitHubCheckRun $run): string => $run->name.' ('.($run->appSlug ?? 'unknown App').', '.($run->conclusion ?? $run->status ?? 'no conclusion').')', $named))
            .'. A skipped, neutral, or stale run, or a run of that name from another App, causes this. Fix the workflow or the Project merge check.');

        return RequiredCheckState::Pending;
    }

    /**
     * The failed runs that the change answers for, as settling judges them too.
     *
     * @param  list<GitHubCheckRun>  $runs
     * @return list<GitHubCheckRun>
     */
    private static function changeFailures(array $runs): array
    {
        return array_values(array_filter(TaskPullRequestCheck::failures($runs), static fn (GitHubCheckRun $run): bool => ! $run->infrastructure()));
    }

    /** @param  list<GitHubCheckRun>  $runs */
    private function evidence(Task $group, array $runs): string
    {
        return implode("\n\n", array_map(function (GitHubCheckRun $run) use ($group): string {
            $failure = TaskPullRequestCheck::fromRun($run);

            return 'Check '.$failure->name.' failed'.($failure->url !== null ? ' ('.$failure->url.')' : '').".\n\n".$this->checkOutput->read($group, $failure);
        }, $runs));
    }

    /** The subtask title and the implementer's summary, without a directive that would make GitHub skip CI. */
    private static function message(Task $task, TaskComment $receipt): string
    {
        return (string) preg_replace(self::SkipDirectives, '', $task->title."\n\n".$receipt->body);
    }

    /** @param  array<GitHubCheckSuite>  $suites */
    private static function named(array $suites): string
    {
        return implode(', ', array_map(static fn (GitHubCheckSuite $suite): string => $suite->label(), $suites));
    }

    /** CI did not run to completion: the code is not at fault. Asks for assistance; no reminder, no budget charge. */
    private function infrastructure(TaskScheduler $scheduler, Task $group, Task $task, TaskComment $receipt, string $commit, string $what): void
    {
        $this->assistance($scheduler, $group, $task, $receipt, 'CI did not run to completion on handoff commit '.$commit.': '.$what.'. Check the runners.');
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
