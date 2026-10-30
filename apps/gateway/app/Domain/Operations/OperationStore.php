<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Routes\DirectRoutePublication;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\OperationStep;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Every write to an operation and its steps. Each change takes the next `sequence` in the same transaction as the
 * change itself, so a reader that sees sequence N sees every change up to N. Each change then sends its notices.
 */
final readonly class OperationStore
{
    public function __construct(private OperationBroadcaster $broadcaster, private OperationIdempotency $idempotency) {}

    public static function lockKey(string $targetType, int $targetId): string
    {
        return "{$targetType}:{$targetId}";
    }

    /**
     * Creates a queued operation that holds its target until it ends.
     *
     * An operation of a kind in `$supersedes` that holds the target and that no worker started gives the target up: it
     * ends `cancelled` with `operation.superseded` in the same transaction that creates this one. A worker that claims
     * it first keeps the target, and this start is busy.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $supersedes
     *
     * @throws ResourceOperationException `operation.target_busy` while another operation holds the target
     */
    public function create(string $kind, string $targetType, int $targetId, ?int $nodeId, array $input, ?string $triggeredBy, ?string $requestId, array $supersedes = []): Operation
    {
        if ($this->idempotency->key !== null && $this->idempotency->kind !== $kind) {
            throw new LogicException("Operation start [{$kind}] does not match its StartsOperation kind [{$this->idempotency->kind}].");
        }

        $writer = DirectRoutePublication::handles($kind) ? DirectRoutePublication::writer($kind) : null;
        $lockKey = $writer === 'direct' ? null : self::lockKey($targetType, $targetId);
        $parent = $this->idempotency->parent;
        if ($parent !== null) {
            if ($this->idempotency->childIntent === null || $kind !== $this->idempotency->childIntent['kind'] || $nodeId !== $this->idempotency->childIntent['node_id']) {
                throw new ResourceOperationException('operation.child_forbidden', 'The owning admission changed the bound kind or Node.', 422);
            }
            $holder = $lockKey === null ? null : Operation::query()->where('lock_key', $lockKey)->lockForUpdate()->first();
            $ancestor = $parent;
            while ($holder !== null && $ancestor !== null) {
                if ($holder->id === $ancestor->id && ! $ancestor->status->terminal()) {
                    $lockKey = null;
                    break;
                }
                $ancestor = $ancestor->parent_operation_id === null ? null : Operation::query()->find($ancestor->parent_operation_id);
            }
        }
        $superseded = null;

        try {
            $operation = DB::transaction(function () use ($kind, $targetType, $targetId, $nodeId, $lockKey, $input, $triggeredBy, $requestId, $supersedes, $writer, &$superseded): Operation {
                $holder = $lockKey === null || $supersedes === [] ? null : Operation::query()->where('lock_key', $lockKey)->whereIn('kind', $supersedes)->first();
                $now = Carbon::now();
                $superseded = $holder instanceof Operation
                    && $this->cancelUnstarted($holder, $now, ['error_code' => 'operation.superseded', 'cancel_requested_at' => $now]) ? $holder : null;

                $created = Operation::query()->create([
                    'kind' => $kind,
                    'idempotency_key' => $this->idempotency->key,
                    'idempotency_fingerprint' => $this->idempotency->fingerprint,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'node_id' => $nodeId,
                    'lock_key' => $lockKey,
                    'input' => $input,
                    'state' => ['executor_target' => ['node_id' => $nodeId, 'target_type' => $targetType, 'target_id' => $targetId],
                        ...($writer === null ? [] : ['route_publication_writer' => $writer])],
                    'status' => OperationStatus::Queued,
                    'sequence' => 1,
                    'attempts' => 0,
                    'triggered_by' => $triggeredBy,
                    'request_id' => $requestId,
                ]);
                if ($this->idempotency->parent !== null) {
                    $created->forceFill(['parent_operation_id' => $this->idempotency->parent->id, 'child_key' => $this->idempotency->childKey, 'run_constraints' => $this->idempotency->parent->run_constraints ?? []])->save();
                }

                return $created;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (($existing = $this->idempotency->replay($kind)) !== null) {
                return $existing;
            }

            $holder = Operation::query()->where('lock_key', $lockKey)->value('id');

            throw new ResourceOperationException(
                'operation.target_busy',
                'Another operation on this target has not ended. Follow it, or cancel it, before you start another.',
                409,
                $exception,
                is_int($holder) ? ['operation_id' => $holder] : [],
            );
        }

        if ($superseded instanceof Operation) {
            $this->broadcaster->changed($superseded->refresh(), true);
        }

        $this->broadcaster->created($operation);

        return $operation;
    }

    /**
     * Records a terminal no-effect receipt if admission is absent. The unique kind/key constraint settles a race
     * with a delayed start; the winner's fingerprint is checked before returning either receipt.
     *
     * @param  array<string, mixed>  $input
     */
    public function fenceAdmission(string $kind, string $key, string $fingerprint, string $targetType, int $targetId, ?int $nodeId, array $input, string $triggeredBy, string $requestId): Operation
    {
        try {
            $operation = Operation::query()->create([
                'kind' => $kind, 'idempotency_key' => $key, 'idempotency_fingerprint' => $fingerprint,
                'target_type' => $targetType, 'target_id' => $targetId, 'node_id' => $nodeId,
                'input' => $input, 'triggered_by' => $triggeredBy, 'request_id' => $requestId,
                'status' => OperationStatus::Cancelled, 'sequence' => 1, 'attempts' => 0,
                'finished_at' => Carbon::now(), 'error_code' => 'operation.admission_fenced',
                'result' => ['admission_fenced' => true],
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $operation = Operation::query()->where('kind', $kind)->where('idempotency_key', $key)->first();
            if ($operation === null) {
                throw $exception;
            }
            if ($operation->idempotency_fingerprint !== $fingerprint) {
                throw new ResourceOperationException('operation.idempotency_conflict', 'The request key belongs to another caller, command, Instance, or input.', 409);
            }

            return $operation;
        }
        $this->broadcaster->created($operation);

        return $operation;
    }

    /**
     * Applies the changes to the operation and to one of its steps under one new sequence.
     *
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $stepChanges
     */
    public function change(Operation $operation, array $changes = [], ?OperationStep $step = null, array $stepChanges = []): void
    {
        $before = $operation->status;
        $cancelRequested = array_key_exists('cancel_requested_at', $changes) && $operation->cancel_requested_at === null;

        DB::transaction(function () use ($operation, $changes, $step, $stepChanges): void {
            $sequence = $this->nextSequence($operation);
            $operation->forceFill([...$changes, 'sequence' => $sequence])->save();
            $step?->forceFill([...$stepChanges, 'sequence' => $sequence])->save();
        });

        $this->broadcaster->changed($operation, $before !== $operation->status || $cancelRequested);
    }

    /**
     * Creates the planned steps, all pending, under one new sequence.
     *
     * @param  list<OperationStepPlan>  $plan
     */
    public function plan(Operation $operation, array $plan): void
    {
        DB::transaction(function () use ($operation, $plan): void {
            $sequence = $this->nextSequence($operation);
            $operation->forceFill(['sequence' => $sequence])->save();

            foreach ($plan as $index => $step) {
                OperationStep::query()->create([
                    'operation_id' => $operation->id,
                    'position' => $index + 1,
                    'name' => $step->name,
                    'status' => OperationStepStatus::Pending,
                    'resumable' => $step->resumable,
                    'sequence' => $sequence,
                ]);
            }
        });

        $operation->unsetRelation('steps');
        $this->broadcaster->changed($operation, false);
    }

    /**
     * Moves a queued operation to running for this worker. Only one worker wins; any other copy of its job does
     * nothing.
     */
    public function claim(Operation $operation, string $worker): bool
    {
        $claimed = DB::transaction(function () use ($operation, $worker): bool {
            $now = Carbon::now();
            $updated = Operation::query()
                ->whereKey($operation->id)
                ->where('status', OperationStatus::Queued->value)
                ->update([
                    'status' => OperationStatus::Running->value,
                    'worker' => $worker,
                    'attempts' => DB::raw('attempts + 1'),
                    'sequence' => DB::raw('sequence + 1'),
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                return false;
            }

            Operation::query()->whereKey($operation->id)->whereNull('started_at')->update(['started_at' => $now]);

            return true;
        });

        $operation->refresh();

        if ($claimed) {
            $this->broadcaster->changed($operation, true);
        }

        return $claimed;
    }

    /** Claims a due read without spending a worker-loss attempt. The conditional write settles duplicate jobs. */
    public function claimWaiting(Operation $operation, string $worker): bool
    {
        $claimed = Operation::query()
            ->whereKey($operation->id)
            ->where('status', OperationStatus::Waiting->value)
            ->where('next_read_at', '<=', Carbon::now())
            ->update([
                'status' => OperationStatus::Running->value,
                'worker' => $worker,
                'sequence' => DB::raw('sequence + 1'),
                'updated_at' => Carbon::now(),
            ]) === 1;
        $operation->refresh();

        if ($claimed) {
            $this->broadcaster->changed($operation, true);
        }

        return $claimed;
    }

    /** Ends an operation that no worker started as cancelled. Any other operation stops at its next step instead. */
    public function cancelQueued(Operation $operation): bool
    {
        $now = Carbon::now();
        $cancelled = $this->cancelUnstarted($operation, $now);
        $operation->refresh();

        if ($cancelled) {
            OperationStep::query()->where('operation_id', $operation->id)->where('status', OperationStepStatus::Pending->value)
                ->update(['status' => OperationStepStatus::Cancelled->value, 'finished_at' => $now, 'sequence' => $operation->sequence]);
            $this->broadcaster->changed($operation, true);
        }

        return $cancelled;
    }

    /**
     * Ends an operation that no worker started as `cancelled`, with the extra changes, and frees its target. A worker
     * that claims it first keeps it. A requeued operation already ran steps; its next worker stops it and finishes its
     * records.
     *
     * @param  array<string, mixed>  $changes
     */
    private function cancelUnstarted(Operation $operation, Carbon $now, array $changes = []): bool
    {
        return Operation::query()
            ->whereKey($operation->id)
            ->where('status', OperationStatus::Queued->value)
            ->where('attempts', 0)
            ->update([
                ...$changes,
                'status' => OperationStatus::Cancelled->value,
                'lock_key' => null,
                'finished_at' => $now,
                'sequence' => DB::raw('sequence + 1'),
                'updated_at' => $now,
            ]) === 1;
    }

    /** Returns a running operation whose worker was lost to the queue, so the next worker resumes or fails it. */
    public function requeue(Operation $operation): bool
    {
        $requeued = Operation::query()
            ->whereKey($operation->id)
            ->where('status', OperationStatus::Running->value)
            ->where('worker', $operation->worker)
            ->update([
                'status' => OperationStatus::Queued->value,
                'worker' => null,
                'sequence' => DB::raw('sequence + 1'),
                'updated_at' => Carbon::now(),
            ]) === 1;

        $operation->refresh();

        if ($requeued) {
            $this->broadcaster->changed($operation, true);
        }

        return $requeued;
    }

    private function nextSequence(Operation $operation): int
    {
        Operation::query()->whereKey($operation->id)->increment('sequence');
        $sequence = Operation::query()->whereKey($operation->id)->value('sequence');

        if (! is_int($sequence)) {
            throw new LogicException("Operation [{$operation->id}] has no sequence.");
        }

        return $sequence;
    }
}
