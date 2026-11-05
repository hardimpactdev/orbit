<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskGroupStatus;
use App\Jobs\Proof\CancelProofGroup;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * The way out for a proof run whose own cancel step did not end its group: the run was cancelled, timed out or failed before
 * that step. The group is cancelled through the normal cancel intent, which destroys its VM.
 */
final readonly class ProofRunCleanup
{
    /** One job admits the cancel, away from the locks of the request or projection that found the leftover. */
    public function schedule(Operation $proof): void
    {
        DB::afterCommit(static fn () => CancelProofGroup::dispatch($proof->id));
    }

    /**
     * Admits the cancel under its own key, because the request key of proof:run belongs to the proof.run Operation, so a group
     * that a retry already cancelled keeps its one cancel.
     *
     * @throws ResourceOperationException when the group is busy for a moment, such as while a step still holds it; the job tries again
     */
    public function cancelGroup(Operation $proof): void
    {
        $group = Task::topLevel()->find($proof->input['group_id'] ?? 0);
        if (! $group instanceof Task || $group->status === TaskGroupStatus::Cancelled) {
            return;
        }
        $identity = app(OperationIdempotency::class);
        $prior = [$identity->kind, $identity->key, $identity->fingerprint];
        $key = 'proof:'.$group->id.':tasks.cancel';
        [$identity->kind, $identity->key, $identity->fingerprint] = ['tasks.cancel', $key, hash('sha256', $key)];
        try {
            app(TaskCancelIntent::class)->start($group, 'proof-run', null);
        } catch (ResourceOperationException $exception) {
            if (! in_array($exception->errorCode, ['tasks.not_cancellable', 'tasks.group_closed'], true)) {
                throw $exception;
            }
        } finally {
            [$identity->kind, $identity->key, $identity->fingerprint] = $prior;
        }
    }
}
