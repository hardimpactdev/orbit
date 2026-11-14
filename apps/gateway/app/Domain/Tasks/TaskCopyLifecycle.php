<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationStatus;
use App\Jobs\Tasks\DestroyTaskCopy;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;

/**
 * What the end of a task copy's create or destroy does to the group that owns the copy (docs/cli/task/start.mmd). An Instance that no
 * group owns, or that has no container, is no task copy: both calls then change nothing. Each runs inside its projection's transaction.
 */
final readonly class TaskCopyLifecycle
{
    /** Opens the reason of the assistance request that a failed completion records on its group; a succeeded completion clears only that request. */
    public const string CompletionFailedReason = 'The completion of the task copy failed: ';

    /**
     * The create or the completion of a copy ended, however it ended. A success runs a group that waits for it once the copy is ready. An
     * expired budget changes nothing: the run may still be stopping or be reopened, and the group stays reserved with its copy until the
     * Operation ends again or `task:cancel` destroys the copy. Any other end of a completion keeps the copy, whose thread was usable, and
     * the group reserved with a failure assistance request (docs/reference/tasks.md#a-failed-completion). Any other end of a create fails
     * a group that still runs and queues the copy's destroy.
     */
    public function createEnded(Operation $operation): void
    {
        $owned = $this->owned($operation->target_id);
        if ($owned === null) {
            return;
        }
        [$instance, $group] = $owned;
        if ($operation->error_code === 'operation.timed_out') {
            return;
        }
        if ($operation->status === OperationStatus::Succeeded) {
            // A create that succeeded before the copy is fully set up leaves the group waiting for that.
            if (self::ready($operation, $instance) && $group->status === TaskGroupStatus::Reserved) {
                $group->update(['status' => TaskGroupStatus::Running, 'started_at' => $group->started_at ?? now(), 'reserved_at' => null, 'capacity_wait_reason' => null]);
            }
            if (self::ready($operation, $instance) && $group->assistance_kind === AssistanceKind::Failure
                && str_starts_with((string) $group->assistance_reason, self::CompletionFailedReason)) {
                TaskAssistance::apply($group);
            }

            return;
        }
        if ($operation->kind === 'instance.complete') {
            // A removal that owns the copy leaves it no failure to retry, and an ended group asks for nothing.
            $failure = self::completionFailure($instance);
            if ($failure === null || ! in_array($group->status, TaskGroupStatus::active(), true)) {
                return;
            }
            // It replaces any open failure, so the held copy always shows; it never replaces an open direction request.
            $requires = isset($failure['requires']) ? ' Fix the Project\'s Process definition first: the retry installs the definitions as they are.' : '';
            TaskAssistance::apply($group, AssistanceKind::Failure, reason: self::CompletionFailedReason."Operation {$failure['operation_id']} "
                ."ended at step {$failure['failed_step']} with {$failure['error_code']}.{$requires} Retry: {$failure['retry']}.", replaceFailure: true);

            return;
        }
        if (in_array($group->status, TaskGroupStatus::active(), true)) {
            $group->update(['status' => TaskGroupStatus::Failed, 'settled_at' => now(), 'reserved_at' => null, 'capacity_wait_reason' => null]);
        }
        DestroyTaskCopy::dispatch($instance->id);
    }

    /**
     * The failed completion that holds the copy, or null: the one place that decides it. The copy is still `source_resolved` and its last
     * `instance.complete` ended other than succeeded and other than by an expired budget. A completion that still runs, or a retry that
     * runs, is no failure: the copy is then only not ready. A copy that a removal owns, or that is active, has none. `requires` is there
     * only when the retry needs a changed Process definition first (InstanceCompleteProjection::recovery).
     *
     * @return array{operation_id: int, failed_step: string, error_code: string, retry: string, requires?: string}|null
     */
    public static function completionFailure(Instance $copy): ?array
    {
        if ($copy->status !== InstanceState::SourceResolved) {
            return null;
        }
        $completion = Operation::query()->where('kind', 'instance.complete')->where('target_type', 'instance')->where('target_id', $copy->id)
            ->orderByDesc('id')->first();
        if ($completion === null || ! $completion->status->terminal() || $completion->status === OperationStatus::Succeeded
            || $completion->error_code === 'operation.timed_out') {
            return null;
        }
        $result = $completion->result ?? [];
        $step = $completion->failed_step ?? $result['failed_step'] ?? null;
        $code = $completion->error_code ?? $result['error_code'] ?? null;
        $recovery = is_array($result['recovery'] ?? null) ? $result['recovery'] : [];
        $retry = is_array($recovery['retry'] ?? null) ? $recovery['retry'] : [];
        $requires = $retry['requires'] ?? null;

        return ['operation_id' => $completion->id, 'failed_step' => is_string($step) ? $step : 'complete',
            'error_code' => is_string($code) ? $code : 'operation.'.$completion->status->value, 'retry' => 'orbit instance:complete '.$copy->id,
            ...(is_string($requires) ? ['requires' => $requires] : [])];
    }

    /**
     * Whether the copy is ready for its task: the one place that decides it. "Fully set up" is the Instance's `active` state
     * (InstanceState::Active). A succeeded create leaves a clone `source_resolved`; its `instance.complete` makes it active, a nested copy
     * with its healthy fleet. OperationStore calls createEnded at the end of both.
     */
    public static function ready(Operation $operation, Instance $copy): bool
    {
        return $operation->status === OperationStatus::Succeeded && $copy->status === InstanceState::Active;
    }

    /**
     * The destroy of a copy succeeded, before its row goes. The group lets go of it; a group that still runs is cancelled with its open
     * subtasks, because its work place is gone. A request that DestroyTaskCopy recorded when it kept the copy has no cause left, and
     * only that request ends, as a task VM release ends its own.
     */
    public function destroyed(int $instanceId): void
    {
        $group = $this->owned($instanceId)[1] ?? null;
        if ($group === null) {
            return;
        }
        if (in_array($group->status, TaskGroupStatus::active(), true)) {
            app(TaskGroupEnding::class)->settle($group, [], 'tasks.cancel');

            return;
        }
        $group->taskable()->dissociate();
        $group->save();
        if ($group->assistance_kind === AssistanceKind::RemovalFailure && str_starts_with((string) $group->assistance_reason, DestroyTaskCopy::KeptReason)) {
            TaskAssistance::apply($group);
        }
    }

    /** @return array{0: Instance, 1: Task}|null The copy and the group that owns it and is bound to it. */
    private function owned(int $instanceId): ?array
    {
        $instance = Instance::query()->find($instanceId);
        if ($instance === null || $instance->task_workspace_owner_id === null || ! $instance->runtime()->exists()) {
            return null;
        }
        $group = Task::topLevel()->lockForUpdate()->find($instance->task_workspace_owner_id);

        return $group === null || $group->taskable_id !== $instance->id || $group->taskable_type !== $instance->getMorphClass() ? null : [$instance, $group];
    }
}
