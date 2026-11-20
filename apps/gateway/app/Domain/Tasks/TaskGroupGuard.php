<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Models\AgentThread;
use App\Models\Task;
use App\Models\TaskCheck;

/** ADR 0122: the refusals that keep Backlog preparation apart from scheduled work. */
final class TaskGroupGuard
{
    /** Every assistance reason from the deliverable path gate before implementer start begins with this. */
    public const string DeliverableGatePrefix = 'Deliverable path validation ';

    /**
     * A subtask declares no topology: Orbit composes none. An empty list, which an older client may still send, means
     * no topology and is ignored. Any other list is refused by name.
     *
     * @param  array<mixed>  $topology  the validated `topology` list of one subtask
     */
    public static function noTopology(array $topology): void
    {
        if ($topology !== []) {
            throw new ResourceOperationException('tasks.orchestration_unsupported', 'Task groups cannot declare a topology.', 422);
        }
    }

    /**
     * The one rule for a task that leaves backlog (docs/reference/tasks.md#tasks-and-subtasks): its own deliverables, or at least two
     * subtasks that each have deliverables; never one subtask. The shape is checked first, so a lone subtask is always told to fold.
     *
     * @param  bool|null  $own  whether the task's own deliverables are nonempty; null for a task that has none of its own
     * @param  array<string, bool>  $subtasks  whether each subtask has deliverables, keyed by the name an error shows, in position order
     */
    public static function leavesBacklog(?bool $own, array $subtasks): void
    {
        if ($own !== null) {
            if (! $own) {
                throw self::ownDeliverablesMissing();
            }

            return;
        }
        if ($subtasks === []) {
            throw self::noSubtasks();
        }
        if (count($subtasks) === 1) {
            throw self::oneSubtask();
        }
        $missing = array_keys(array_filter($subtasks, static fn (bool $has): bool => ! $has));
        if ($missing !== []) {
            throw self::deliverablesMissing(array_map(strval(...), $missing));
        }
    }

    public static function noSubtasks(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.no_subtasks',
            message: __('A task leaves backlog with its own deliverables, or with at least two subtasks that each have deliverables.'),
            status: 422,
        );
    }

    /**
     * A task leaves backlog with no subtask or with at least two, never with one (docs/reference/tasks.md#a-task-with-no-subtask).
     * A task with one subtask that left backlog before this rule keeps working until it ends.
     */
    public static function oneSubtask(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.one_subtask',
            message: __('A task leaves backlog with no subtask or with at least two. Fold the one subtask into the task: give the task its deliverables and no subtask.'),
            status: 422,
        );
    }

    /** A task with no subtask leaves backlog only with deliverables of its own. */
    private static function ownDeliverablesMissing(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.subtask_deliverables_missing',
            message: __('A task leaves backlog with at least one deliverable of its own, or with at least two subtasks that each have deliverables.'),
            status: 422,
        );
    }

    /**
     * ADR 0133: a group moves to todo only when every subtask has deliverables.
     *
     * @param  list<string>  $subtasks  the subtasks without deliverables
     */
    public static function deliverablesMissing(array $subtasks): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.subtask_deliverables_missing',
            message: __('Every subtask needs at least one deliverable before the group moves to todo.'),
            status: 422,
            details: ['subtasks' => implode(', ', $subtasks)],
        );
    }

    /** ADR 0133: a subtask of a group that left backlog is never without deliverables. */
    public static function deliverablesRequired(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.subtask_deliverables_missing',
            message: __('A subtask of a group outside backlog needs at least one deliverable.'),
            status: 422,
        );
    }

    /**
     * The deliverable path gate asked for assistance and no implementer exists yet, so there is no thread to resume.
     * A resolution clears the assistance and the next tick runs the gate again. Called under the group and subtask locks.
     */
    private static function deliverableGateBlocked(Task $group, Task $task): bool
    {
        return $group->status === TaskGroupStatus::Running
            && $task->parent_id === $group->id && $task->status === TaskStatus::Running && $task->assistance_requested
            && $task->assistance_kind !== AssistanceKind::Direction && $group->assistance_kind !== AssistanceKind::Direction
            && is_string($task->assistance_reason) && str_starts_with($task->assistance_reason, self::DeliverableGatePrefix)
            && $task->implementer_agent_thread_id === null
            && ! AgentThread::query()->where('task_id', $task->id)->where('role', TaskThreadRole::Implementer->value)
                ->where('external_id', 'not like', AgentThread::PendingPrefix.'%')->exists();
    }

    /**
     * The one rule for whether a started subtask's deliverables may change, and by which correction; null keeps them locked
     * (docs/reference/tasks.md#correct-the-deliverables-of-a-started-subtask). A todo subtask follows the planning rules instead.
     * Called before the locks to validate, and again under the group and subtask locks before the change.
     */
    public static function deliverableCorrection(Task $group, Task $task): ?DeliverableCorrection
    {
        if (($check = self::deliverableCorrectionCheck($group, $task)) !== null) {
            return new DeliverableCorrection(DeliverableCorrectionKind::InvalidDeliverable, $check);
        }
        if (self::deliverableGateBlocked($group, $task)) {
            return new DeliverableCorrection(DeliverableCorrectionKind::PathGate);
        }
        $check = self::failedCopyCheck($group, $task);

        return $check === null ? null : new DeliverableCorrection(DeliverableCorrectionKind::FailedCopyCheck, $check);
    }

    /**
     * The newest handoff check of a task copy's current subtask, which has started, when it failed and no correction answered it yet.
     */
    private static function failedCopyCheck(Task $group, Task $task): ?TaskCheck
    {
        if (in_array($group->status, TaskGroupStatus::endedStatuses(), true) || TaskInstanceWorkspace::forGroup($group) === null
            || $task->parent_id !== $group->id || ! in_array($task->status, [TaskStatus::Running, TaskStatus::Reviewing], true)) {
            return null;
        }
        $current = Task::query()->where('parent_id', $group->id)
            ->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])
            ->orderBy('position')->orderBy('id')->value('id');
        if ($current !== $task->id) {
            return null;
        }
        $check = TaskCheck::query()->where('task_id', $task->id)->where('kind', TaskCheckKind::Handoff)->latest('id')->first();

        // One correction answers one check, whichever correction it was.
        return $check instanceof TaskCheck && $check->status === TaskCheckStatus::Failed
            && $task->copy_correction_check_id !== $check->id && $task->deliverable_correction_check_id !== $check->id ? $check : null;
    }

    public static function reasonUnexpected(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.reason_unexpected',
            message: __('A reason goes only with a correction of a started subtask\'s deliverables after its newest handoff check failed.'),
            status: 422,
        );
    }

    /** Called under the group and subtask locks before consuming the one recovery. */
    private static function deliverableCorrectionCheck(Task $group, Task $task): ?TaskCheck
    {
        if ($group->status !== TaskGroupStatus::Running || $task->status !== TaskStatus::Running
            || ! $task->assistance_requested || self::deliverableCorrectionRecorded($task)) {
            return null;
        }

        $check = TaskCheck::query()->where('task_id', $task->id)->where('kind', TaskCheckKind::Handoff)->latest('id')->first();
        if (! $check instanceof TaskCheck || $check->status !== TaskCheckStatus::Failed
            || $check->failed_step !== 'invalid_deliverable'
            || $check->task_comment_id !== $task->completion_handoff_comment_id) {
            return null;
        }
        $receipt = $task->comments()->find($check->task_comment_id);

        return $receipt?->completion_attempt === $task->completion_attempt ? $check : null;
    }

    private static function deliverableCorrectionRecorded(Task $task): bool
    {
        return $task->deliverable_correction_check_id !== null;
    }

    public static function deliverablesLocked(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.deliverables_locked',
            message: __('Deliverables change only while the group is in backlog or the subtask is todo.'),
            status: 409,
        );
    }

    public static function notInBacklog(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.not_in_backlog',
            message: __('This change is allowed only while the task group is in backlog.'),
            status: 409,
        );
    }

    public static function groupClosed(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.group_closed',
            message: __('An ended task group cannot accept new subtasks.'),
            status: 409,
        );
    }

    public static function alreadyClaimed(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'tasks.already_claimed',
            message: __('This task group already has a workspace reservation.'),
            status: 409,
        );
    }
}
