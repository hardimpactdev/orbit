<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Actions\TaskVms\DeleteTaskVmAction;
use App\Actions\TaskVms\WakeTaskVmAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Instances\InstanceState;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\RemotePrograms\RemoteProgramFailed;
use App\Domain\RemotePrograms\RemoteProgramFailure;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskFinalReview;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskRemoteBranch;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmState;
use App\Jobs\TaskVms\DestroyTaskVm;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskVm;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

/**
 * Finds and removes a task group's workspace, including one that a claim provisioned but never attached.
 *
 * Provisioning records the group as the workspace's owner when it creates the Instance, before any remote work, so
 * every path that ends a group finds its workspace through that owner, attached or not. An Instance without that
 * owner is never a task workspace, whatever its name or branch.
 *
 * A workspace on a task VM goes with its VM. A workspace whose VM is already gone uses the generic forced Instance
 * remover, which runs the Project's teardown steps, then deletes the checkout and only then the Instance row. A
 * refused teardown or source check leaves both in place. The caller records assistance and returns the error, so
 * the checkout stays named by a record.
 */
final readonly class RemoveTaskWorkspaceAction
{
    public const string OwnershipRefusedPrefix = 'Workspace ownership refused: ';

    public const string RemovalFailedPrefix = 'Workspace removal failed: ';

    public const string MergeCleanupFailedPrefix = 'Merged pull request cleanup failed: ';

    public const string HandoffBranchLeftPrefix = 'Handoff branch not deleted: ';

    public function __construct(
        private InstanceRemover $remover,
        private TaskPullRequestPublisher $publisher,
    ) {}

    /**
     * Whether a live claim still owns the group's unattached workspace. A group reserved within
     * `orbit.tasks.reserved_timeout_seconds` has a claim in flight, and that claim removes the workspace
     * when it finds the group ended. A group reserved longer than the bound has no live claim.
     */
    public function claimInFlight(Task $group): bool
    {
        return $group->status === TaskGroupStatus::Reserved
            && ! Task::topLevel()->whereKey($group->id)->workspaceClaimReleased()->exists();
    }

    public static function reservationCutoff(): CarbonInterface
    {
        return now()->subSeconds(Config::integer('orbit.tasks.reserved_timeout_seconds'));
    }

    /** Waits before lease admission can snapshot an unavailable VM with reviewed work still to preserve. */
    public static function awaitVmPreservation(Task $group, Instance $instance): void
    {
        $vm = TaskVm::forNode($instance->node_id);
        $reason = $vm?->state === TaskVmState::Destroying
            ? "The task VM of Instance [{$instance->name}] is already being destroyed."
            : $vm?->hostSweepWaitReason();
        if ($reason !== null && TaskFinalReview::reconcileRemovalPush($group) !== null) {
            throw new TaskWorkspaceWaiting($reason);
        }
    }

    /**
     * Removes the workspace the group owns, and returns it, or null when the group owns none. Every task path removes
     * a workspace here, so only the owner decides which Instance goes.
     *
     * The workspace goes with its task VM, or through the generic Instance remover when the VM is gone. The source
     * and row stay when removal refuses. A workspace on a parked task VM stays: the VM is queued to start, and removal
     * fails with `task_vm.parked` until it is ready.
     */
    public function remove(Task $group): ?Instance
    {
        $instance = $group->workspace()->first();
        if (! $instance instanceof Instance) {
            return null;
        }
        $group->setRelation('taskable', $instance);
        $leases = app(TaskWorkspaceLease::class);
        $leases->run($group, 'teardown', 'teardown:'.$instance->id, function () use ($group, $instance, $leases): void {
            if (! app(WakeTaskVmAction::class)->forInstance($instance)) {
                throw new TaskVmException('task_vm.parked', "The task VM of Instance [{$instance->name}] is parked. Orbit starts it and removes the workspace once it is ready.");
            }
            $commit = TaskFinalReview::reconcileRemovalPush($group);
            if ($commit !== null) {
                $this->publisher->push($group, $commit);
                TaskFinalReview::recordRemovalPush($group, $commit);
            }
            $lease = $leases->remoteStarted($instance);
            try {
                $this->removeWithLease($instance);
                $leases->remoteFinished($lease);
            } catch (Throwable $exception) {
                if (! $this->uncertainRemoteRemoval($exception)) {
                    $leases->remoteFinished($lease);
                }
                throw $exception;
            }
        });

        return $instance;
    }

    private function uncertainRemoteRemoval(Throwable $exception): bool
    {
        for ($cause = $exception; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof ProcessTimedOutException
                || ($cause instanceof ResourceOperationException && ($cause->details['outcome'] ?? null) === 'unconfirmed')
                || ($cause instanceof RuntimeConvergenceException && $cause->result !== null && in_array($cause->result->exitCode, [124, 137, 143, 255], true))
                || ($cause instanceof RemoteProgramFailed && $cause->reason !== RemoteProgramFailure::Unreachable
                    && ($cause->exitCode === null || in_array($cause->exitCode, [124, 137, 143, 255], true)))) {
                return true;
            }
        }

        return false;
    }

    private function removeWithLease(Instance $instance): void
    {
        $vm = TaskVm::query()->live()->where('node_id', $instance->node_id)->first();
        if ($vm instanceof TaskVm) {
            $this->removeWithTaskVm($instance, $vm);

            return;
        }

        $this->remover->execute($instance, true);
    }

    /**
     * A task VM holds one group's workspace, and its checkout may live on a workspace volume that goes with the VM.
     * So the VM goes instead of the checkout: the Project's teardown runs in the guest while it is ready, unless an
     * earlier removal attempt ran it. The agent transcripts are copied to the Gateway, then the VM is deleted and the
     * workspace records are forgotten offline. The queued `DestroyTaskVm` removes the Node.
     */
    private function removeWithTaskVm(Instance $instance, TaskVm $vm): void
    {
        $started = ! in_array($instance->status, [InstanceState::Reserved, InstanceState::CheckoutPrepared], true);
        if ($vm->state === TaskVmState::Ready && $started && ! $instance->removalMember()->exists()) {
            app(ProjectLifecycleRunner::class)->run($instance, LifecyclePhase::Teardown);
        }
        if ($vm->state === TaskVmState::Ready && $vm->group_id !== null && $vm->node_id !== null) {
            app(ArchiveAgentTranscriptsAction::class)->execute($vm->group_id, $vm->node_id);
        }
        app(DeleteTaskVmAction::class)->execute($vm);
        DestroyTaskVm::dispatch($vm->id);
    }

    /** Asks the group for assistance and keeps the checkout and Instance row for a later retry. */
    public function recordFailure(Task $group, Throwable $exception, ?string $prefix = null, bool $replaceDirection = false): void
    {
        if ($exception instanceof TaskWorkspaceWaiting || ($exception instanceof TaskPullRequestException && $exception->pending)) {
            return;
        }
        for ($cause = $exception; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof TaskVmException && in_array($cause->errorCode, [TaskVmError::WorkspaceMismatch->value, TaskVmError::ForeignInstance->value], true)) {
                $prefix = self::OwnershipRefusedPrefix;
                break;
            }
        }
        TaskAssistance::apply($group, AssistanceKind::RemovalFailure, null, ($prefix ?? self::RemovalFailedPrefix).$exception->getMessage(), replaceFailure: true, replaceDirection: $replaceDirection);
    }

    /**
     * Finishes the removal once the group's workspace is gone: deletes the group's handoff branch, then clears
     * assistance that the removal recorded. Another cause is left alone.
     *
     * A handoff pushed `task-{id}-handoff` for CI ([Handoff CI](/reference/tasks#handoff-ci)). The branch holds
     * nothing to keep, so a failed delete never holds the removal. It asks for assistance instead and leaves that
     * request open for tick groups. External groups report the failure without assistance. Only a repeated cancel
     * or completion, which ends here again, tries the delete once more.
     */
    public function finishRemoval(Task $group): void
    {
        if (TaskCheck::query()->whereIn('task_id', $group->tasks()->select('id'))->whereNotNull('commit_sha')->exists()) {
            try {
                $this->publisher->deleteHandoffBranch($group);
            } catch (TaskPullRequestException $exception) {
                if ($group->groupOrchestration() === TaskOrchestration::External) {
                    report($exception);

                    return;
                }
                TaskAssistance::apply($group, AssistanceKind::RemovalFailure, null, self::HandoffBranchLeftPrefix.$exception->getMessage()
                    .' Delete '.TaskRemoteBranch::handoff($group).' on GitHub or restore the App access, then cancel or complete the task again.',
                    replaceFailure: true, replaceDirection: true);

                return;
            }
        }
        $group->refresh();
        if (! Task::topLevel()->whereKey($group->id)->removalFailure()->exists()) {
            return;
        }

        TaskAssistance::apply($group);
    }
}
