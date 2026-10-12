<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Actions\TaskVms\DeleteTaskVmAction;
use App\Actions\TaskVms\WakeTaskVmAction;
use App\Domain\Compute\SandboxState;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Instances\InstanceState;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Domain\Tasks\TaskWorkspaceTopology;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmState;
use App\Jobs\TaskVms\DestroyTaskVm;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskSandbox;
use App\Models\TaskVm;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Finds and removes a task group's workspace, including one that a claim provisioned but never attached.
 *
 * A claim can provision the `task-{group id}` Instance and then fail, or stop, before it attaches it. The
 * workspace keeps that deterministic name and branch, so every path that ends a group finds it by name
 * when the group holds no Instance.
 *
 * Shared workspace removal uses the generic forced Instance remover. Sandbox removal uses its recorded compute ownership.
 * A workspace on a task VM goes with its VM. The generic remover runs the Project's teardown steps, then deletes the
 * checkout and only then the Instance row. A refused teardown or source check leaves both in place. The
 * caller records assistance and returns the error, so the checkout stays named by a record.
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
        } elseif ($group->task_compute === TaskCompute::Vm) {
            foreach (TaskSandbox::query()->where('group_id', $group->id)->where('state', '!=', SandboxState::Destroyed)->get() as $sandbox) {
                app(RemoveTaskSandboxAction::class)->unattached($sandbox);
            }
        }

        return $instance;
    }

    /**
     * Runs Project teardown through the generic Instance remover. The source and row stay when removal refuses.
     * Releases the group's topology first; a failed release keeps the workspace for a retry. A workspace on a
     * parked task VM stays: the VM is queued to start, and removal fails with `task_vm.parked` until it is ready.
     */
    public function remove(Instance $instance, ?Task $expectedGroup = null): void
    {
        if (! app(WakeTaskVmAction::class)->forInstance($instance)) {
            throw new TaskVmException('task_vm.parked', "The task VM of Instance [{$instance->name}] is parked. Orbit starts it and removes the workspace once it is ready.");
        }
        if ($instance->task_sandbox_id !== null) {
            app(RemoveTaskSandboxAction::class)->workspace($instance, $expectedGroup);

            return;
        }
        $group = Task::topLevel()->where('taskable_type', $instance->getMorphClass())->where('taskable_id', $instance->id)->first();
        if ($group instanceof Task) {
            app(TaskWorkspaceTopology::class)->release($instance, $group->id);
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
     * earlier removal attempt ran it, then the VM is deleted and the workspace records are forgotten offline. The
     * queued `DestroyTaskVm` removes the Node.
     */
    private function removeWithTaskVm(Instance $instance, TaskVm $vm): void
    {
        $started = ! in_array($instance->status, [InstanceState::Reserved, InstanceState::CheckoutPrepared], true);
        if ($vm->state === TaskVmState::Ready && $started && ! $instance->removalMember()->exists()) {
            app(ProjectLifecycleRunner::class)->run($instance, LifecyclePhase::Teardown);
        }
        app(DeleteTaskVmAction::class)->execute($vm);
        DestroyTaskVm::dispatch($vm->id);
    }

    /** Asks the group for assistance and keeps the checkout and Instance row for a later retry. */
    public function recordFailure(Task $group, Throwable $exception, ?string $prefix = null): void
    {
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
