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
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmState;
use App\Jobs\TaskVms\DestroyTaskVm;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskVm;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

/**
 * Finds and removes a task group's workspace, including one that a claim provisioned but never attached.
 *
 * A claim can provision the `task-{group id}` Instance and then fail, or stop, before it attaches it. The
 * workspace keeps that deterministic name and branch, so every path that ends a group finds it by name
 * when the group holds no Instance.
 *
 * A workspace on a task VM goes with its VM. A workspace whose VM is already gone uses the generic forced Instance
 * remover, which runs the Project's teardown steps, then deletes the checkout and only then the Instance row. A
 * refused teardown or source check leaves both in place. The caller records assistance and returns the error, so
 * the checkout stays named by a record.
 */
final readonly class RemoveTaskWorkspaceAction
{
    public const string RemovalFailedPrefix = 'Workspace removal failed: ';

    public const string MergeCleanupFailedPrefix = 'Merged pull request cleanup failed: ';

    public function __construct(
        private InstanceRemover $remover,
    ) {}

    /** The attached Instance, or the group's unattached `task-{group id}` workspace. */
    public function find(Task $group): ?Instance
    {
        $attached = $group->taskable;

        if ($attached instanceof Instance) {
            return $attached;
        }

        $name = TaskWorkspaceName::for($group);

        return Instance::query()
            ->where('project_id', $group->project_id)
            ->where('name', $name)
            ->where('branch_override', $name)
            ->first();
    }

    /**
     * Whether a live claim still owns the group's unattached workspace. A group reserved within
     * `orbit.tasks.reserved_timeout_seconds` has a claim in flight, and that claim removes the workspace
     * when it finds the group ended. A group reserved longer than the bound has no live claim.
     */
    public function claimInFlight(Task $group): bool
    {
        return $group->status === TaskGroupStatus::Reserved
            && $group->reserved_at instanceof CarbonInterface
            && $group->reserved_at->greaterThan(self::reservationCutoff());
    }

    public static function reservationCutoff(): CarbonInterface
    {
        return now()->subSeconds(Config::integer('orbit.tasks.reserved_timeout_seconds'));
    }

    /** Removes the group's workspace when it has one. It returns the removed Instance. */
    public function execute(Task $group): ?Instance
    {
        $instance = $this->find($group);

        if ($instance instanceof Instance) {
            $this->remove($instance, $group);
        }

        return $instance;
    }

    /**
     * Removes the workspace with its task VM, or through the generic Instance remover when the VM is gone. The source
     * and row stay when removal refuses. A workspace on a parked task VM stays: the VM is queued to start, and removal fails with `task_vm.parked` until it is ready.
     */
    public function remove(Instance $instance, ?Task $group = null): void
    {
        $group ??= Task::topLevel()->where('taskable_type', $instance->getMorphClass())->where('taskable_id', $instance->id)->first();
        if (! $group instanceof Task && is_string($instance->branch_override)
            && $instance->name === $instance->branch_override && preg_match('/\Atask-([1-9][0-9]*)\z/', $instance->branch_override, $match) === 1) {
            $group = Task::topLevel()->where('project_id', $instance->project_id)->find((int) $match[1]);
        }
        if ($group instanceof Task && ($group->project_id !== $instance->project_id || $this->find($group)?->id !== $instance->id)) {
            throw new TaskWorkspaceWaiting('The workspace removal owner does not match this Instance.');
        }
        if ($group instanceof Task) {
            $group->setRelation('taskable', $instance);
            $leases = app(TaskWorkspaceLease::class);
            $leases->run($group, 'teardown', 'teardown:'.$instance->id, function () use ($instance, $leases): void {
                $lease = $leases->remoteStarted($instance);
                try {
                    $this->removeWithLease($instance);
                    $leases->remoteFinished($lease);
                } catch (Throwable $exception) {
                    if ($this->uncertainRemoteRemoval($exception)) {
                        // The synchronous holder also survives a Gateway crash. Keep it
                        // after a caught transport loss while teardown may still run.
                        throw $exception;
                    }
                    $leases->remoteFinished($lease);
                    throw $exception;
                }
            });

            return;
        }
        $this->removeWithLease($instance);
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
        if (! app(WakeTaskVmAction::class)->forInstance($instance)) {
            throw new TaskVmException('task_vm.parked', "The task VM of Instance [{$instance->name}] is parked. Orbit starts it and removes the workspace once it is ready.");
        }
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
    public function recordFailure(Task $group, Throwable $exception, ?string $prefix = null): void
    {
        if ($exception instanceof TaskWorkspaceWaiting) {
            return;
        }
        TaskAssistance::apply($group, AssistanceKind::Failure, null, ($prefix ?? self::RemovalFailedPrefix).$exception->getMessage(), replaceFailure: true);
    }

    /** Clears assistance that this removal recorded, once the checkout is gone. Another cause is left alone. */
    public function clearFailure(Task $group): void
    {
        $group->refresh();
        $reason = $group->assistance_reason;

        if (! is_string($reason)) {
            return;
        }

        if (! str_starts_with($reason, self::RemovalFailedPrefix) && ! str_starts_with($reason, self::MergeCleanupFailedPrefix)) {
            return;
        }

        $group->update(TaskAssistance::cleared());
    }
}
