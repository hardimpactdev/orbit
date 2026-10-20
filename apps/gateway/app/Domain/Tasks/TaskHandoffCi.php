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
use Illuminate\Support\Facades\Cache;

/**
 * The handoff's tests run in CI on the handoff commit ([Handoff CI](/reference/tasks#handoff-ci)). After the task
 * check passed in the workspace, Orbit commits the checked tree, pushes it to the task branch, makes sure a pull
 * request runs CI on it, and reads the Project's merge check on exactly that commit. Nothing waits in a request:
 * each tick reads the result again, at most once a minute for one handoff.
 */
final readonly class TaskHandoffCi
{
    /** The `failed_step` of a handoff check whose tests failed in CI, or that CI could not run on. */
    public const string FailedStep = 'ci';

    private const int ReadIntervalSeconds = 60;

    public function __construct(
        private TaskWorkspaceSigner $signer,
        private TaskPullRequestPublisher $publisher,
        private TaskPullRequestMerger $merger,
        private TaskPullRequestCheckOutput $checkOutput,
        private TaskBroadcasts $broadcasts,
        private TaskPullRequestWatcher $pullRequestWatcher,
    ) {}

    /**
     * The merge check's state on the handoff commit. `Failed` means this call stored the failure on the check,
     * as `failed_step` `ci` with the evidence as its output. Every other state but `Passed` means the handoff waits.
     */
    public function test(TaskScheduler $scheduler, Task $group, Task $task, TaskCheck $check, TaskComment $receipt, ?int $threadId, string $checkName): RequiredCheckState
    {
        $commit = $check->commit_sha ?? $this->commit($scheduler, $group, $task, $check, $receipt, $threadId);
        if ($commit === null) {
            return RequiredCheckState::Pending;
        }
        if ($commit === TaskReviewBase::commit($task)) {
            // The subtask changed nothing, so the handoff commit is its start commit, which an earlier handoff or main already tested.
            return RequiredCheckState::Passed;
        }
        if (! Cache::add('tasks.handoff-ci.'.$check->id, true, self::ReadIntervalSeconds)) {
            return RequiredCheckState::Pending;
        }
        try {
            $runs = $this->merger->checkRuns($group, $commit);
        } catch (TaskPullRequestException $exception) {
            $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

            return RequiredCheckState::Unreadable;
        }
        $state = RequiredCheckState::of($runs, $commit, $checkName);
        if ($state === RequiredCheckState::Missing) {
            // No run yet: the commit is not on GitHub, no pull request runs CI on it, or CI has not started.
            return $this->publish($scheduler, $group, $task, $check, $commit);
        }
        $scheduler->clearCommunicationFailures($task);
        if ($state === RequiredCheckState::Failed) {
            $this->fail($group, $check, $this->evidence($group, $commit, $checkName, $runs));
        }

        return $state;
    }

    /** Commits the checked tree as the subtask's work, under the receipt's workspace fence, and records the commit on the check. */
    private function commit(TaskScheduler $scheduler, Task $group, Task $task, TaskCheck $check, TaskComment $receipt, ?int $threadId): ?string
    {
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            $scheduler->recordCommunicationFailure($task, $group, 'The task workspace is unavailable.');

            return null;
        }
        $commit = app(TaskWorkspaceLease::class)->withReceipt($group, $task->id, $threadId,
            fn (): ?string => $this->signer->commit($instance, $task->title."\n\n".$receipt->body));
        if ($commit === null) {
            $scheduler->recordCommunicationFailure($task, $group, 'Orbit could not commit the handoff for CI.');

            return null;
        }
        if (TaskCheck::query()->whereKey($check->id)->whereNull('commit_sha')->update(['commit_sha' => $commit, 'updated_at' => now()]) === 1) {
            $this->broadcasts->groupChanged($group->id);
        }
        $check->refresh();

        return $check->commit_sha;
    }

    private function publish(TaskScheduler $scheduler, Task $group, Task $task, TaskCheck $check, string $commit): RequiredCheckState
    {
        try {
            $pullRequest = $this->publisher->publishHandoff($group, $commit);
        } catch (TaskPullRequestException $exception) {
            if ($exception->pending) {
                return RequiredCheckState::Missing;
            }
            $moved = $this->authorPush($group, $commit);
            if ($moved === null) {
                $scheduler->recordCommunicationFailure($task, $group, $exception->getMessage());

                return RequiredCheckState::Missing;
            }
            $branch = TaskRemoteBranch::for($group);
            $this->fail($group, $check, 'The pull request author pushed '.$moved.' to '.$branch.', so Orbit cannot push '.$commit.' without forcing. '
                .'Merge origin/'.$branch.' into the task branch and resolve any conflicts. Do not rebase and do not force-push.');

            return RequiredCheckState::Failed;
        }
        $scheduler->clearCommunicationFailures($task);
        if ($pullRequest->state !== GitHubPullRequestState::Open || ! $pullRequest->conflicts()) {
            // An ended pull request holds the group through the branch and settling watches.
            return RequiredCheckState::Missing;
        }
        // GitHub runs no pull_request workflow while the pull request conflicts with its base.
        $base = $pullRequest->baseRef ?? 'the base branch';
        $this->fail($group, $check, 'GitHub reports that the task branch conflicts with '.$base.', so CI cannot run on '.$commit.'. '
            .'Merge origin/'.$base.' into the task branch and resolve the conflicts. Do not rebase and do not force-push.');

        return RequiredCheckState::Failed;
    }

    /**
     * The head of an incoming pull request that its author pushed after Orbit's last push: a head that no
     * handoff, review, or fixup of the group knows. Null when nothing shows such a push.
     */
    private function authorPush(Task $group, string $commit): ?string
    {
        if (! $group->reviewsIncomingPullRequest()) {
            return null;
        }
        $head = $this->pullRequestWatcher->health($group)?->headSha;
        if (! is_string($head) || $head === $commit || TaskFinalReview::isReviewed($group, $head)) {
            return null;
        }
        $subtasks = $group->tasks()->select('id');
        $known = TaskCheck::query()->whereIn('task_id', $subtasks)->where('commit_sha', $head)->exists()
            || $group->tasks()->where('fixup_head_sha', $head)->exists();

        return $known ? null : $head;
    }

    /** @param list<GitHubCheckRun> $runs */
    private function evidence(Task $group, string $commit, string $checkName, array $runs): string
    {
        $failed = TaskPullRequestCheck::withoutExplainedRollups(array_values(array_filter($runs, static fn (GitHubCheckRun $run): bool => $run->failed())));
        if ($failed === []) {
            return 'Check '.$checkName.' did not pass on '.$commit.', or a run of that name came from an App other than github-actions.';
        }
        $sections = array_map(function (GitHubCheckRun $run) use ($group): string {
            $check = TaskPullRequestCheck::fromRun($run);

            return 'Check '.$check->name.' failed'.($check->url !== null ? ' ('.$check->url.')' : '').".\n\n".$this->checkOutput->read($group, $check);
        }, $failed);

        return implode("\n\n", $sections);
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
