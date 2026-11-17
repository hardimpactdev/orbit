<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskCopyEnd;
use App\Domain\Tasks\TaskCopyWork;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskFinalReview;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskInstanceWorkspace;
use App\Domain\Tasks\TaskOperations;
use App\Domain\Tasks\TaskStatus;
use App\Models\Instance;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class CompleteTaskGroupAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private RemoveTaskWorkspaceAction $workspace,
        private RepositoryPullRequestAccess $access,
        private GitHubApi $github,
        private StopTaskSubtaskAction $stop,
        private TaskExecutionLock $execution,
        private TaskFinalReview $review,
        private TaskCopyWork $work,
        private TaskCopyEnd $end,
    ) {}

    /**
     * Completes a settling group, or a running/reviewing group whose watched PR ended or whose task copy's work is already on the
     * default branch (a hand-carry), and removes its workspace. A task copy that holds work that is not published refuses it.
     *
     * A manual complete still ends the group when removal fails, keeps the Instance, and reports the failure
     * on the completed group. Merge cleanup passes `$finishWhenRemovalFails` false so a failed removal leaves
     * the group settling for the sweep.
     */
    public function execute(Task $group, bool $finishWhenRemovalFails = true): Task
    {
        $this->requireExtension->execute();

        return $this->execution->synchronized($group->id, fn (): Task => $this->complete($group, $finishWhenRemovalFails), wait: false);
    }

    /** Keep worker start and group completion exclusive through the remote stop and terminal write. */
    private function complete(Task $group, bool $finishWhenRemovalFails): Task
    {
        $group->refresh()->load(['project', 'tasks', 'taskable']);

        // Approved work that origin does not have refuses the completion before it changes anything: task:publish comes first.
        $carried = false;
        if (in_array($group->status, [TaskGroupStatus::Running, TaskGroupStatus::Reviewing], true)) {
            try {
                $ended = $this->endedPullRequest($group);
            } catch (ResourceOperationException $notEnded) {
                // No ended watched pull request: a copy group's work may have reached the default branch another way.
                $this->assertCarried($group, $notEnded);
                [$ended, $carried] = [null, true];
            }
            $this->review->assertOnOrigin($group);
            $this->assertCopyDiscardable($group);
            if ($ended !== null) {
                $this->authorizeEndedPullRequest($group, ...$ended);
            }
        } elseif ($group->status !== TaskGroupStatus::Completed && ! in_array($group->status, TaskGroupStatus::awaitingCompletion(), true)) {
            throw $this->notReady('status');
        } else {
            $this->review->assertOnOrigin($group);
            if ($group->status !== TaskGroupStatus::Completed) {
                $this->assertCopyDiscardable($group);
            }
        }
        app(TaskOperations::class)->cancelOpen($group);

        if ($group->status === TaskGroupStatus::Completed) {
            $this->removeOrFinish($group, $finishWhenRemovalFails);

            return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
        }

        if (in_array($group->status, [TaskGroupStatus::Running, TaskGroupStatus::Reviewing], true)) {
            $this->completeOpenSubtasks($group, $carried);

            $this->removeOrFinish($group, $finishWhenRemovalFails);

            return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
        }

        if (! $this->removeOrFinish($group, $finishWhenRemovalFails)) {
            $group->update(['status' => TaskGroupStatus::Completed, 'settled_at' => $group->settled_at ?? now()]);

            return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
        }

        $group->refresh();
        $group->taskable()->dissociate();
        $group->status = TaskGroupStatus::Completed;
        $group->settled_at ??= now();
        $group->save();

        return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
    }

    /**
     * Reads how the watched pull request ended. Null when an earlier completion stored it already.
     *
     * @return array{url:string,state:string,mergedAt:?string}|null
     */
    private function endedPullRequest(Task $group): ?array
    {
        if (in_array($group->watched_pr_completion, ['merged', 'closed'], true)) {
            return null;
        }
        $url = $group->watched_pr_url;
        $repository = GitHubRepository::fromOrigin($group->project->repository_url ?? '');
        $number = $repository !== null && is_string($url) ? $repository->pullRequestNumber($url) : null;
        if ($repository === null || $number === null) {
            throw $this->notReady();
        }
        try {
            $pullRequest = $this->github->pullRequest($this->access->cachedReadToken($repository), $repository, $number);
            $state = $pullRequest->state->value;
        } catch (Throwable) {
            throw $this->notReady();
        }
        if (! in_array($state, ['merged', 'closed'], true)) {
            throw $this->notReady();
        }

        return ['url' => $url, 'state' => $state, 'mergedAt' => $state === 'merged' ? $pullRequest->mergedAt : null];
    }

    /** Stores authorization separately, so a crash or rollback resumes without another GitHub read. */
    private function authorizeEndedPullRequest(Task $group, string $url, string $state, ?string $mergedAt): void
    {
        $this->execution->synchronized($group->id, function () use ($group, $url, $state, $mergedAt): void {
            DB::transaction(function () use ($group, $url, $state, $mergedAt): void {
                $locked = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
                if (! in_array($locked->status, [TaskGroupStatus::Running, TaskGroupStatus::Reviewing], true)
                    || $locked->watched_pr_url !== $url) {
                    throw $this->notReady('status');
                }
                if ($locked->watched_pr_completion === null) {
                    $locked->update(['watched_pr_completion' => $state, 'merged_at' => $locked->merged_at ?? $mergedAt]);
                }
            });
        }, wait: false);
        $group->refresh();
    }

    /**
     * A running or reviewing copy group without an ended watched pull request completes only when the copy's content read finds its
     * committed work on the default branch, and that work is not empty (docs/reference/tasks.md#published-work-of-a-copy). The
     * refusal names why.
     */
    private function assertCarried(Task $group, ResourceOperationException $notEnded): void
    {
        $copy = TaskInstanceWorkspace::forGroup($group);
        if ($copy === null) {
            throw $notEnded;
        }
        $reason = $this->work->refusal($group, $copy);
        if ($reason !== null) {
            throw $this->notReady($reason);
        }
    }

    /**
     * The copy's destroy takes what it holds, so a copy that holds uncommitted, untracked, stashed or other-branch work, or that cannot
     * be read, refuses the completion before it changes anything, as a cancel without discard does (TaskCopyEnd). A completed group's
     * retry reads nothing: the completion that ended it read the copy.
     */
    private function assertCopyDiscardable(Task $group): void
    {
        $copy = TaskInstanceWorkspace::forGroup($group);
        if ($copy !== null) {
            TaskCopyEnd::assertDiscardable($this->end->workspace($group, $copy->instance), discard: false);
        }
    }

    /** Remote stops precede the transaction; no subtask is ended unless the parent can end with it. */
    private function completeOpenSubtasks(Task $group, bool $carried): void
    {
        $this->execution->synchronized($group->id, function () use ($group, $carried): void {
            $open = [TaskStatus::Todo, TaskStatus::Running, TaskStatus::Reviewing];
            do {
                $stopped = [];
                foreach ($group->tasks()->whereIn('status', $open)->orderBy('id')->get() as $task) {
                    $stopped[$task->id] = $this->stop->snapshot($group, $task);
                    $this->stop->execute($group, $task);
                }

                $completed = DB::transaction(function () use ($group, $open, $stopped, $carried): bool {
                    $locked = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
                    if ($locked->status === TaskGroupStatus::Completed) {
                        return true;
                    }
                    if (! in_array($locked->status, [TaskGroupStatus::Running, TaskGroupStatus::Reviewing], true)
                        || (! $carried && ! in_array($locked->watched_pr_completion, ['merged', 'closed'], true))) {
                        throw $this->notReady('status');
                    }
                    $tasks = $locked->tasks()->whereIn('status', $open)->orderBy('id')->lockForUpdate()->get();
                    $current = [];
                    foreach ($tasks as $task) {
                        $current[$task->id] = $this->stop->snapshot($locked, $task);
                    }
                    if ($current !== $stopped) {
                        return false;
                    }
                    foreach ($tasks as $task) {
                        $task->checks()->where('status', TaskCheckStatus::Running)
                            ->update(['status' => TaskCheckStatus::Cancelled, 'finished_at' => now()]);
                        $task->update([
                            'status' => TaskStatus::Cancelled,
                            'settled_at' => now(),
                            'completion_summary' => 'Cancelled by operator.',
                        ]);
                    }
                    $locked->update([
                        'status' => TaskGroupStatus::Completed,
                        'settled_at' => $locked->settled_at ?? now(),
                    ]);

                    return true;
                });
            } while (! $completed);
        }, wait: false);
        $group->refresh()->load(['project', 'tasks', 'taskable']);
    }

    /** `$reason`: why a running copy group is not ready, as docs/cli/task/complete.mdx lists it. */
    private function notReady(string $reason = 'pull_request_not_ended'): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.not_settling',
            message: __('The task group is not ready to complete.'),
            status: 409,
            details: ['reason' => $reason],
        );
    }

    /**
     * Removes the workspace. A manual complete that cannot remove it still finishes and returns false.
     * Merge cleanup rethrows so the group stays settling. A repeated complete retries at once.
     */
    private function removeOrFinish(Task $group, bool $finishWhenRemovalFails): bool
    {
        try {
            // Under the admission lock, so a tick cannot park the group's task VM while its workspace is removed.
            $this->execution->synchronized($group->id, fn () => $this->removeWorkspace($group), wait: false);
        } catch (Throwable $exception) {
            $this->workspace->recordFailure($group, $exception, replaceDirection: $finishWhenRemovalFails);
            if (! $finishWhenRemovalFails) {
                throw $exception;
            }

            return false;
        }

        return true;
    }

    /** Deletes the checkout; a refusal leaves the checkout and Instance for removeOrFinish to report. */
    private function removeWorkspace(Task $group): void
    {
        $instanceId = $group->taskable_id;

        $this->workspace->remove($group);

        $this->workspace->finishRemoval($group);

        if ($instanceId !== null && ! Instance::query()->whereKey($instanceId)->exists()) {
            $group->refresh();
            $group->taskable()->dissociate();
            $group->save();
        }
    }
}
