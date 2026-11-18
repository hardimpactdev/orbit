<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Domain\Instances\InstanceDestroyKind;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskFinalReview;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
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
 * A task copy goes through its `instance.destroy`. Any other workspace, such as one on a removed task VM, is refused with
 * `operation.kind_unavailable` and stays, named by its record; the caller records assistance and returns the error.
 */
final readonly class RemoveTaskWorkspaceAction
{
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
     * A task copy goes through its destroy. The source and row stay when removal refuses. A task VM workspace has no removal any more:
     * the task VMs and their release are removed (docs/reference/old-paths.md). The caller has proved that origin has the approved work
     * ({@see TaskFinalReview::assertOnOrigin()}).
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
        if ($instance->runtime()->exists()) {
            $this->removeCopy($instance);
        }

        // A workspace that is no task copy sat on a task VM; the VMs and their release are removed (docs/reference/old-paths.md).
        throw new ResourceOperationException('operation.kind_unavailable', 'This workspace is no task copy. Task VMs and their release are removed, so Orbit cannot remove it.', 503);
    }

    /**
     * A task copy goes through its normal `instance.destroy` with force, the destroy that `task:cancel` admits (docs/cli/task/complete.mmd).
     * Origin has the approved work, so what else the copy holds goes with it. An open destroy of the copy is reused. When the destroy
     * succeeds, the group lets go of the copy (TaskLifecycle::destroyed); when it fails, the group keeps it and a later complete retries.
     */
    private function removeCopy(Instance $instance): never
    {
        $open = Operation::query()->where('target_type', 'instance')->where('target_id', $instance->id)->where('kind', InstanceDestroyKind::forInstance($instance))
            ->whereNotIn('status', OperationStatus::terminalValues())->exists();
        if (! $open) {
            app(StartInstanceDestroyAction::class)->execute($instance, true, 'tasks');
        }

        throw new TaskWorkspaceWaiting("The destroy of task copy [{$instance->name}] removes the workspace when it ends.");
    }

    /** Asks the group for assistance and keeps the checkout and Instance row for a later retry. */
    public function recordFailure(Task $group, Throwable $exception, ?string $prefix = null, bool $replaceDirection = false): void
    {
        if ($exception instanceof TaskWorkspaceWaiting) {
            return;
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
