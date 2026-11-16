<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A task copy group settles itself when its watched pull request merged (docs/reference/tasks.md#a-copy-group-settles-on-merge).
 * The `project.observe` run reads the pull request in Swamp; Orbit never merges, readies or comments on it. The projection of
 * that run records the merge here, under its transaction, and completes the group after the commit through task:complete's
 * own action. Every step is safe to repeat, so the next observation retries whatever did not finish.
 */
final readonly class TaskCopySettle
{
    /** The refusals of a complete that a person must resolve; any other refusal is a busy group that the next tick retries. */
    private const array Refusals = ['tasks.copy_unpublished_work', 'tasks.copy_workspace_unknown', 'tasks.approved_work_unpublished', 'tasks.not_settling'];

    /** A completed copy group whose merged pull request ended it, and which still holds its copy: its destroy failed. */
    public static function retries(Task $group): bool
    {
        return $group->status === TaskGroupStatus::Completed && $group->taskable_id !== null && $group->watched_pr_completion === 'merged';
    }

    /**
     * A completed group whose earlier complete could not admit the copy's destroy: the complete records that request, and clears it
     * itself once the removal succeeds, so the tick retries it. Any other request waits for a person.
     */
    private static function removalFailed(Task $group): bool
    {
        return $group->status === TaskGroupStatus::Completed && $group->assistance_kind === AssistanceKind::RemovalFailure
            && str_starts_with((string) $group->assistance_reason, RemoveTaskWorkspaceAction::RemovalFailedPrefix);
    }

    /**
     * Records how the watched pull request of a locked copy group ended, and after the commit completes a merged one. A closed
     * pull request is recorded by the observation only: a person decides. A group with an open Operation of its own, or one whose
     * earlier refusal still waits for a person, is left for the next tick.
     */
    public function observed(Task $group, string $state, ?string $mergedAt): void
    {
        if ($state !== 'merged' || TaskInstanceWorkspace::forGroup($group) === null || ($group->assistance_requested && ! self::removalFailed($group))) {
            return;
        }
        if (in_array($group->status, [TaskGroupStatus::Running, TaskGroupStatus::Reviewing], true)) {
            if ($group->watched_pr_completion === null) {
                if ($mergedAt === null) {
                    throw new ResourceOperationException('operation.result_invalid', 'A merged pull request observation must name when it merged.', 422);
                }
                $group->update(['watched_pr_completion' => 'merged', 'merged_at' => $group->merged_at ?? $mergedAt]);
            } elseif ($group->watched_pr_completion !== 'merged') {
                return;
            }
        } elseif (! self::retries($group)) {
            return;
        }
        if ($this->busy($group) || $this->destroying($group)) {
            return;
        }
        DB::afterCommit(fn () => $this->complete($group));
    }

    /** The open Operations of the group: a turn, a check or a publish runs, so the complete waits for the next tick. */
    private function busy(Task $group): bool
    {
        return Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
            ->whereNotIn('status', OperationStatus::terminalValues())->exists();
    }

    /** An Operation still holds the copy, such as the destroy that an earlier complete admitted: its destroy would be refused. */
    private function destroying(Task $group): bool
    {
        return Operation::query()->where('target_type', 'instance')->where('target_id', $group->taskable_id)
            ->whereNotIn('status', OperationStatus::terminalValues())->exists();
    }

    /**
     * A failed destroy leaves the group `completed` with its copy, for the next tick. A refusal that a person must resolve is recorded
     * once as assistance; the group is then skipped until a person resolves it.
     */
    private function complete(Task $group): void
    {
        try {
            app(CompleteTaskGroupAction::class)->execute($group, finishWhenRemovalFails: false);
        } catch (Throwable $exception) {
            $group->refresh();
            if ($exception instanceof ResourceOperationException && in_array($exception->errorCode, self::Refusals, true)) {
                TaskAssistance::apply($group, $group->status === TaskGroupStatus::Completed ? AssistanceKind::RemovalFailure : AssistanceKind::Failure,
                    reason: 'Settle on merge refused: '.$exception->errorCode.'. '.$exception->getMessage());
            } elseif (! $exception instanceof ResourceOperationException) {
                report($exception);
            }
        }
    }
}
