<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Instances\InstanceState;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskFinalReview;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSlotRuns;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskVm;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Finds and removes a task group's workspace, including one that a claim provisioned but never attached.
 *
 * Provisioning records the group as the workspace's owner when it creates the Instance, before any remote work, so
 * every path that ends a group finds its workspace through that owner, attached or not. An Instance without that
 * owner is never a task workspace, whatever its name or branch.
 *
 * A workspace on a task VM goes with its VM: the slot release deletes both. A workspace with no live task VM is
 * refused with `operation.kind_unavailable` and stays, named by its record; the caller records assistance and returns
 * the error.
 */
final readonly class RemoveTaskWorkspaceAction
{
    public const string OwnershipRefusedPrefix = 'Workspace ownership refused: ';

    public const string RemovalFailedPrefix = 'Workspace removal failed: ';

    public const string MergeCleanupFailedPrefix = 'Merged pull request cleanup failed: ';

    public const string HandoffBranchLeftPrefix = 'Handoff branch not deleted: ';

    public function __construct(
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

    /**
     * Returns null when the group owns no workspace. An owned workspace stays until its native removal can complete.
     *
     * The workspace goes with its task VM, through the slot release. The source and row stay when removal refuses. A parked task VM goes
     * as it is: the release deletes a stopped VM as well, and nothing on it is worth a start. The caller has proved that origin has the
     * approved work ({@see TaskFinalReview::assertOnOrigin()}).
     */
    public function remove(Task $group): null
    {
        $instance = $group->workspace()->first();
        if (! $instance instanceof Instance) {
            return null;
        }
        $this->removeWorkspace($instance);
    }

    private function removeWorkspace(Instance $instance): never
    {
        $vm = TaskVm::query()->live()->where('node_id', $instance->node_id)->first();
        if ($vm instanceof TaskVm) {
            $this->removeWithTaskVm($instance, $vm);
        }

        throw new ResourceOperationException('operation.kind_unavailable', 'Task workspace removal requires its native ending DAG.', 503);
    }

    /**
     * A task VM holds one group's workspace, and its checkout may live on a workspace volume that goes with the VM.
     * So the VM goes instead of the checkout: the Project's teardown runs in the guest while it is ready, unless an
     * earlier removal attempt ran it. The turn results already hold the transcripts. The release of the slot
     * (`task_vm.slot_release`) deletes the VM, its seed clone and its hub peer. The release projection deletes the
     * workspace Instance and the Node and unbinds the group, so this call only admits the release and then waits for it.
     * A row without a slot number is not a task start's VM, and no release deletes it.
     */
    private function removeWithTaskVm(Instance $instance, TaskVm $vm): never
    {
        if ($vm->slot_number === null) {
            throw new ResourceOperationException('task_vm.release_unavailable', "Task VM [{$vm->name}] has no slot, so no release can delete it.", 409,
                details: ['task_vm_id' => $vm->id]);
        }
        $started = ! in_array($instance->status, [InstanceState::Reserved, InstanceState::CheckoutPrepared], true);
        if ($vm->state === TaskVmState::Ready && $started && ! $instance->removalMember()->exists()) {
            app(ProjectLifecycleRunner::class)->run($instance, LifecyclePhase::Teardown);
        }
        app(TaskVmSlotRuns::class)->startRelease($vm, 'tasks-complete');

        throw new TaskWorkspaceWaiting("The release of task VM [{$vm->name}] removes the workspace of Instance [{$instance->name}] when it ends.");
    }

    /** Asks the group for assistance and keeps the checkout and Instance row for a later retry. */
    public function recordFailure(Task $group, Throwable $exception, ?string $prefix = null, bool $replaceDirection = false): void
    {
        if ($exception instanceof TaskWorkspaceWaiting) {
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
                report($exception);

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
