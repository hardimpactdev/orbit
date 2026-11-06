<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmImageRefreshIntent;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use Closure;

/** The task group of a proof.run: its keyed admissions and its cancel. The group carries the VM, so a cancelled proof must cancel it. */
final readonly class ProofGroup
{
    /** The Activity trigger of the cancels that the proof machinery makes for the groups of its own runs. */
    public const string Trigger = 'proof-run';

    /** The kind of the Operation that owns the group of one proof run. */
    public const string OwnerKind = 'proof.command';

    /**
     * Admits one task operation under its own key, because the request key of proof:run belongs to the proof.run Operation.
     *
     * @param  Closure(): Operation  $start
     */
    public function admit(Task $group, string $kind, Closure $start): Operation
    {
        $key = 'proof:'.$group->id.':'.$kind;

        return app(OperationIdempotency::class)->under($kind, $key, hash('sha256', $key), $start);
    }

    /**
     * Cancels the task group that `$owner` targets through a keyed admission, so a retry never admits a second cancel. Only the Operation
     * that owns the group may ask: its `proof.command`, or its image refresh. An Operation that targets anything else cancels nothing.
     * `$trigger` names who asks, in the Activity of the cancel.
     *
     * @throws ResourceOperationException when the group is busy for a moment, such as while a step still holds it; the job tries again
     */
    public function cancel(Operation $owner, string $trigger = self::Trigger): void
    {
        if ($owner->target_type !== 'task_group' || ! in_array($owner->kind, [self::OwnerKind, TaskVmImageRefreshIntent::Kind], true)) {
            return;
        }
        $group = Task::topLevel()->find($owner->target_id);
        // A cancelled run marks its group cancelled and leaves its VM as it was, in provisioning or failed, with its slot taken; that group still needs its cancel.
        if ($group === null || ($group->status === TaskGroupStatus::Cancelled && ! TaskVm::query()->where('group_id', $group->id)->where('state', '!=', TaskVmState::Destroyed)->exists())) {
            return;
        }
        try {
            $this->admit($group, 'tasks.cancel', fn () => app(TaskCancelIntent::class)->start($group, $trigger, null));
        } catch (ResourceOperationException $exception) {
            if (! in_array($exception->errorCode, ['tasks.not_cancellable', 'tasks.group_closed'], true)) {
                throw $exception;
            }
        }
    }
}
