<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofGroup;
use App\Jobs\Tasks\DestroyTaskCopy;
use App\Jobs\Tasks\RetryTaskCopyCreate;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;

/**
 * What the end of a task copy's create or destroy does to the group that owns the copy (docs/cli/task/start.mmd). An Instance that no
 * group owns, or that has no container, is no task copy: both calls then change nothing. Each runs inside its projection's transaction.
 */
final readonly class TaskLifecycle
{
    /** Opens the reason of the assistance request that a failed completion records on its group; a succeeded completion clears only that request. */
    public const string CompletionFailedReason = 'The completion of the task copy failed: ';

    /** Opens the reason of the assistance request that the second failed create of a group's copy records; a ready copy clears only that request. */
    public const string CreateFailedReason = 'The create of the task copy failed twice: ';

    /**
     * The create or the completion of a copy ended, however it ended. A success runs a group that waits for it once the copy is ready. An
     * expired budget changes nothing: the run may still be stopping or be reopened, and the group stays reserved with its copy until the
     * Operation ends again or `task:cancel` destroys the copy. Any other end of a completion keeps the copy, whose thread was usable, and
     * the group reserved with a failure assistance request (docs/reference/tasks.md#a-failed-completion). Any other end of a create queues
     * the copy's destroy, and the group that waits for it goes back to `todo` (docs/reference/tasks.md#a-failed-create): the first failure
     * is recorded and the create starts once more when the copy is gone (destroyed); the second records a failure assistance request
     * that names both. A cancel, and the copy of a proof, which its run owns, still fail the group.
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
            if ($operation->kind === 'instance.create' && $group->copy_create_failure !== null) {
                // The retried create succeeded: no retry is owed any more.
                $group->update(['copy_create_failure' => null]);
            }
            // A create that succeeded before the copy is fully set up leaves the group waiting for that.
            if (self::ready($operation, $instance) && $group->status === TaskGroupStatus::Reserved) {
                $group->update(['status' => TaskGroupStatus::Running, 'started_at' => $group->started_at ?? now(), 'reserved_at' => null, 'capacity_wait_reason' => null]);
            }
            if (self::ready($operation, $instance) && $group->assistance_kind === AssistanceKind::Failure
                && (str_starts_with((string) $group->assistance_reason, self::CompletionFailedReason)
                    || str_starts_with((string) $group->assistance_reason, self::CreateFailedReason))) {
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
            $operation->status === OperationStatus::Cancelled || self::proof($group) ? $group->update(['status' => TaskGroupStatus::Failed,
                'settled_at' => now(), 'reserved_at' => null, 'capacity_wait_reason' => null]) : $this->createFailed($operation, $group);
        }
        DestroyTaskCopy::dispatch($instance->id);
    }

    /**
     * A failed create puts the group back in `todo`, still bound to the copy until its destroy: the first records itself for the retry,
     * the second ends the retry and asks for assistance.
     */
    private function createFailed(Operation $operation, Task $group): void
    {
        $result = $operation->result ?? [];
        $step = $operation->failed_step ?? $result['failed_step'] ?? null;
        $code = $operation->error_code ?? $result['error_code'] ?? null;
        $failure = ['operation_id' => $operation->id, 'failed_step' => is_string($step) ? $step : 'create',
            'error_code' => is_string($code) ? $code : 'operation.'.$operation->status->value];
        $first = $group->copy_create_failure;
        $second = $first !== null && $first['operation_id'] !== $operation->id;
        $group->update(['status' => TaskGroupStatus::Todo, 'reserved_at' => null, 'capacity_wait_reason' => null, 'copy_create_failure' => $second ? null : $failure]);
        if ($second) {
            self::askAfterRetry($group, $first, "Operation {$operation->id}, ended at step {$failure['failed_step']} with {$failure['error_code']}");
        }
    }

    /**
     * The failure assistance request after the retry of a failed create also failed: it names the first failure and the retry's.
     *
     * @param  array{operation_id: int, failed_step: string, error_code: string}  $first
     */
    public static function askAfterRetry(Task $group, array $first, string $retry): void
    {
        TaskAssistance::apply($group, AssistanceKind::Failure, reason: self::CreateFailedReason."Operation {$first['operation_id']} ended at step "
            ."{$first['failed_step']} with {$first['error_code']}, and its retry, {$retry}. Orbit removes the copy; start the task again with "
            ."`orbit task:start {$group->id}` once it is gone.", replaceFailure: true);
    }

    /** Whether the group is a proof run's (ProofGroup): its run owns the copy and ends with it, so its create never starts again. */
    private static function proof(Task $group): bool
    {
        return Operation::query()->where('kind', ProofGroup::OwnerKind)->where('target_type', 'task_group')->where('target_id', $group->id)->exists();
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
     * (InstanceState::Active). A succeeded create leaves a clone `source_resolved`; its `instance.complete` makes it active. A nested
     * copy's fleet may still start beside it (InstanceFleetOperation); a proof resets and waits for the fleet itself. OperationStore
     * calls createEnded at the end of both.
     */
    public static function ready(Operation $operation, Instance $copy): bool
    {
        return $operation->status === OperationStatus::Succeeded && $copy->status === InstanceState::Active;
    }

    /**
     * The destroy of a copy succeeded, before its row goes. The group lets go of it; a group that still runs is cancelled with its open
     * subtasks, because its work place is gone. A request that DestroyTaskCopy recorded when it kept the copy has no cause left, and
     * only that request ends, as a task VM release ends its own. A `todo` group whose copy a `task:cancel` destroyed is cancelled too. A
     * `todo` group that records its first failed create starts the create once more, after the commit, now that the copy's name is free;
     * a group that moved out of `todo` meanwhile owes no retry any more.
     */
    public function destroyed(int $instanceId): void
    {
        $group = $this->owned($instanceId)[1] ?? null;
        if ($group === null) {
            return;
        }
        if (in_array($group->status, TaskGroupStatus::active(), true) || self::cancelling($group, $instanceId)) {
            app(TaskGroupEnding::class)->settle($group, 'tasks.cancel');

            return;
        }
        $group->taskable()->dissociate();
        $group->save();
        if ($group->assistance_kind === AssistanceKind::RemovalFailure && str_starts_with((string) $group->assistance_reason, DestroyTaskCopy::KeptReason)) {
            TaskAssistance::apply($group);
        }
        $failure = $group->copy_create_failure;
        if ($failure !== null && $group->status === TaskGroupStatus::Todo) {
            RetryTaskCopyCreate::dispatch($group->id, $failure['operation_id']);
        } elseif ($failure !== null) {
            $group->update(['copy_create_failure' => null]);
        }
    }

    /**
     * Whether a `task:cancel` of the group admitted a destroy of this copy (TaskEnd::cancel). That cancel still runs while its destroy
     * succeeds and leaves the group to the destroy, so any one that names the copy counts.
     */
    private static function cancelling(Task $group, int $instanceId): bool
    {
        return Operation::query()->where('kind', 'tasks.cancel')->where('target_type', 'task_group')->where('target_id', $group->id)
            ->where('input->instance_id', $instanceId)->exists();
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
