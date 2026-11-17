<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use Closure;

/** The task group of a proof.run: its keyed admissions and its cancel. The group carries the copy, so every end of a run must cancel it. */
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
     * @param  string  $attempt  set apart a later admission of the same kind, such as the cancel after a failed destroy of the copy
     */
    public function admit(Task $group, string $kind, Closure $start, string $attempt = ''): Operation
    {
        $key = 'proof:'.$group->id.':'.$kind.$attempt;

        return app(OperationIdempotency::class)->under($kind, $key, hash('sha256', $key), $start);
    }

    /**
     * The key part of the next cancel of a group that owns a copy: each destroy of the copy that ended without success needs a cancel of
     * its own, because the group stays bound to the copy and a replay of the earlier key would admit nothing. Empty for the first cancel.
     */
    private static function cancelAttempt(Task $group): string
    {
        $copy = Instance::query()->where('task_workspace_owner_id', $group->id)->value('id');
        $failed = $copy === null ? 0 : Operation::query()->where('kind', 'instance.destroy')->where('target_type', 'instance')->where('target_id', $copy)
            ->whereIn('status', OperationStatus::terminalValues())->where('status', '!=', OperationStatus::Succeeded)->count();

        return $failed === 0 ? '' : ':'.$failed;
    }

    /**
     * Cancels the task group that `$owner` targets through a keyed admission, so a retry never admits a second cancel. Only the Operation
     * that owns the group may ask: its `proof.command`. An Operation that targets anything else cancels nothing.
     * `$trigger` names who asks, in the Activity of the cancel.
     *
     * @throws ResourceOperationException when the group is busy for a moment, such as while a step still holds it; the job tries again
     */
    public function cancel(Operation $owner, string $trigger = self::Trigger): void
    {
        if ($owner->target_type !== 'task_group' || $owner->kind !== self::OwnerKind) {
            return;
        }
        $group = Task::topLevel()->find($owner->target_id);
        if ($group !== null) {
            $this->discard($group, $trigger);
        }
    }

    /**
     * Cancels a proof copy's task group with discard, which destroys the copy: the group of a run, or of a warm copy that the pool drops
     * (ProofWarmPool). A cancelled group asks nothing.
     *
     * @throws ResourceOperationException when the group is busy for a moment, such as while a step still holds it; the job tries again
     */
    public function discard(Task $group, string $trigger): void
    {
        if ($group->status === TaskGroupStatus::Cancelled) {
            return;
        }
        try {
            // A proof's copy holds only the commit under proof and the runner's evidence: its cancel discards them.
            $this->admit($group, 'tasks.cancel', fn () => app(TaskCancelIntent::class)->start($group, $trigger, null, discard: true), self::cancelAttempt($group));
        } catch (ResourceOperationException $exception) {
            if (! in_array($exception->errorCode, ['tasks.not_cancellable', 'tasks.group_closed'], true)) {
                throw $exception;
            }
        }
    }
}
