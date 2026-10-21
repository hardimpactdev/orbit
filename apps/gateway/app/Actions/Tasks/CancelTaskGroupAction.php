<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class CancelTaskGroupAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private RemoveTaskWorkspaceAction $workspace,
        private NodeReachabilityProbe $reachability,
    ) {}

    public function execute(Task $group): Task
    {
        return app(TaskExecutionLock::class)->synchronized($group->id, fn (): Task => $this->cancel($group), wait: false);
    }

    private function cancel(Task $group): Task
    {
        $this->requireExtension->execute();

        $group->refresh()->load(['project', 'tasks', 'taskable']);

        $unpublished = in_array($group->status, TaskGroupStatus::awaitingCompletion(), true) && ($group->pr_url === null || $group->pr_url === '');
        if ($group->status === TaskGroupStatus::Completed || (in_array($group->status, TaskGroupStatus::awaitingCompletion(), true) && ! $unpublished)) {
            throw new ResourceOperationException(
                errorCode: 'tasks.not_cancellable',
                message: __('A completed task group, or a settling one with a pull request, cannot be cancelled.'),
                status: 409,
            );
        }

        // A live claim owns the workspace it is provisioning. It removes that workspace once it finds the group
        // cancelled, so cancel leaves it alone and only removes what the claim attached before the cancel landed.
        $claimInFlight = $this->workspace->claimInFlight($group) && $group->taskable_id === null;
        $instance = $claimInFlight ? null : $group->workspace()->first();
        // An unreachable Node cannot accept a push or a removal. Cancel still ends the group and keeps the Instance,
        // so the sweep can delete the checkout later. A Node that answers keeps the fail-closed path below.
        $offlineId = $instance instanceof Instance && $this->nodeUnreachable($instance) ? $instance->id : null;

        if ($instance instanceof Instance && $offlineId === null) {
            $this->teardownWorkspace($group, $instance);
        }

        $removedId = $instance instanceof Instance && $offlineId === null ? $instance->id : null;
        $attachedByClaim = DB::transaction(static function () use ($group, $removedId, $offlineId): ?Instance {
            $locked = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
            // A claim can attach the workspace between the checks above and this lock. Cancel removes the group's
            // workspace when it is attached and was not removed above, whether or not it saw a claim in flight. The
            // unreachable Instance stays attached so the sweep can find the checkout.
            $current = $locked->taskable_id !== null ? $locked->workspace()->first() : null;
            $keepOffline = $current instanceof Instance && $current->id === $offlineId;
            $attached = $current instanceof Instance && $current->id !== $removedId && ! $keepOffline ? $current : null;
            if ($keepOffline) {
                TaskAssistance::apply($locked, AssistanceKind::RemovalFailure, null, RemoveTaskWorkspaceAction::RemovalFailedPrefix.'The Node is unreachable.', replaceFailure: true, replaceDirection: true);
            } else {
                $locked->taskable()->dissociate();
            }
            $locked->status = TaskGroupStatus::Cancelled;
            $locked->save();

            return $attached;
        });

        if ($attachedByClaim instanceof Instance) {
            try {
                $this->teardownWorkspace($group, $attachedByClaim);
            } catch (Throwable $exception) {
                // The workspace stays attached for a retry, unless the failed removal already deleted its row.
                $locked = Task::topLevel()->findOrFail($group->id);
                $this->workspace->recordFailure($locked, $exception);
                $remaining = $locked->workspace()->whereKey($attachedByClaim->id)->first();
                if ($remaining instanceof Instance) {
                    $locked->taskable()->associate($remaining);
                    $locked->save();
                }

                throw $exception;
            }
        }
        if ($instance === null && ! $claimInFlight && ! $attachedByClaim instanceof Instance) {
            // No workspace is left, as on a repeated cancel, so this cancel finishes its removal. That deletes a
            // handoff branch that an earlier cancel could not delete.
            $this->workspace->finishRemoval($group);
        }

        foreach ($group->tasks()->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])->get() as $task) {
            $task->update(['status' => TaskStatus::Cancelled, 'settled_at' => now()]);
        }
        $cancelled = $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
        foreach ($group->tasks()->where('assistance_requested', true)->get() as $task) {
            TaskAssistance::apply($task, reason: $task->assistance_reason);
        }
        $cancelled = $group->fresh(['project', 'tasks', 'taskable']) ?? $cancelled;

        return $cancelled;
    }

    /** Cancellation owns one lifecycle admission across preservation and removal. */
    private function teardownWorkspace(Task $group, Instance $instance): void
    {
        $group->setRelation('taskable', $instance);
        $leases = app(TaskWorkspaceLease::class);
        $owner = 'teardown:'.$instance->id;
        $leases->cancelQueued($group, 'cancel-push:'.$group->id);
        try {
            $leases->run($group, 'teardown', $owner, function () use ($group): void {
                $this->removeWorkspace($group);
            });
        } catch (Throwable $exception) {
            $current = Task::topLevel()->findOrFail($group->id);
            $retryable = Task::topLevel()->whereKey($current->id)->workspaceRemovalRetry()->exists();
            if (! $retryable) {
                // This synchronous request did not end the group, so no scheduler path owns its waiter.
                // The group lock prevents another cancellation from consuming or replacing it during cleanup.
                $leases->cancelQueued($group, $owner);
            }
            throw $exception;
        }
    }

    /** The probe is the one check that separates a Node that is gone from a Node that answered and refused. */
    private function nodeUnreachable(Instance $instance): bool
    {
        // A parked VM is intentionally offline; normal removal starts it before preservation.
        if (TaskVm::forNode($instance->node_id)?->state === TaskVmState::Parked) {
            return false;
        }
        $instance->loadMissing('node');

        return $this->reachability->degradation($instance->node) === ExporterDegradationReason::Unreachable;
    }

    /** A refused removal keeps the checkout and the Instance row, asks for assistance, and returns the error. */
    private function removeWorkspace(Task $group): void
    {
        try {
            $this->workspace->remove($group);
            $this->workspace->finishRemoval($group);
        } catch (TaskPullRequestException $exception) {
            // A task VM's push runs in a queued job. Cancel fails until GitHub has the commit, so no approved work is lost.
            throw new ResourceOperationException(
                errorCode: 'tasks.push_failed',
                message: $exception->pending
                    ? __('The workspace stays while its approved commits are pushed to task-:group: :reason Cancel again in a minute.', ['group' => $group->id, 'reason' => $exception->getMessage()])
                    : __('The workspace stays because its approved commits could not be pushed to task-:group: :reason', ['group' => $group->id, 'reason' => $exception->getMessage()]),
                status: 502,
            );
        } catch (Throwable $exception) {
            $this->workspace->recordFailure($group, $exception);

            throw $exception;
        }
    }
}
