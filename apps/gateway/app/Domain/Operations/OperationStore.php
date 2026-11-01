<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Persists intent, optional admission holds and native results. */
final readonly class OperationStore
{
    public function __construct(private OperationBroadcaster $broadcaster, private OperationIdempotency $idempotency) {}

    public static function lockKey(string $targetType, int $targetId): string
    {
        return "{$targetType}:{$targetId}";
    }

    /**
     * Creates a queued Operation. An Instance target is held by default from this start to the terminal status.
     *
     * A holder of a kind in `$supersedes` that no worker started gives the target up first: it ends `cancelled` with
     * `operation.superseded` in the same transaction. A worker that claims it first keeps the target.
     *
     * @param  array<string,mixed>  $input
     * @param  list<string>  $supersedes
     *
     * @throws OperationTargetBusy while another Operation holds the target
     */
    public function create(string $kind, string $targetType, int $targetId, ?int $nodeId, array $input, ?string $triggeredBy, ?string $requestId, ?string $lockKey = null, array $supersedes = []): Operation
    {
        $lockKey ??= $targetType === OperationKinds::InstanceTarget ? self::lockKey($targetType, $targetId) : null;
        if ($this->idempotency->key !== null && $this->idempotency->kind !== $kind) {
            throw new LogicException('The Operation kind must match the request idempotency key.');
        }
        $gatewayJob = OperationKinds::gatewayJob($kind);
        OperationKinds::executor($kind);
        if ($nodeId === null) {
            throw new ResourceOperationException('operation.node_required', 'A direct Operation requires its admitted root Node.', 422);
        }
        $definition = $gatewayJob ? [] : (app(DirectOperation::class)->definition($kind) ?? throw new LogicException('The admitted direct Operation declaration is unavailable.'));
        $fields = array_values(array_unique([...array_keys($definition['private_material'] ?? []), ...array_keys($definition['secret_outputs'] ?? [])]));
        app(DirectOperationPrivateBindings::class)->assertAdmission($input, $fields, $nodeId);
        $superseded = null;
        try {
            $operation = DB::transaction(function () use ($kind, $targetType, $targetId, $nodeId, $input, $triggeredBy, $requestId, $definition, $lockKey, $supersedes, &$superseded): Operation {
                $holder = $lockKey === null || $supersedes === [] ? null : Operation::query()->where('lock_key', $lockKey)->whereIn('kind', $supersedes)->first();
                $now = Carbon::now();
                $superseded = $holder instanceof Operation
                    && $this->cancelUnstarted($holder, $now, ['error_code' => 'operation.superseded', 'cancel_requested_at' => $now]) ? $holder : null;
                $operation = Operation::query()->create([
                    'kind' => $kind, 'idempotency_key' => $this->idempotency->key, 'idempotency_fingerprint' => $this->idempotency->fingerprint,
                    'target_type' => $targetType, 'target_id' => $targetId, 'node_id' => $nodeId, 'lock_key' => $lockKey,
                    'input' => $input, 'state' => [], 'status' => OperationStatus::Queued, 'sequence' => 1, 'attempts' => 0,
                    'triggered_by' => $triggeredBy, 'request_id' => $requestId,
                ]);
                if (isset($definition['workflow'])) {
                    $operation->forceFill(['state' => [...($operation->state ?? []), 'swamp_start' => [
                        'key' => 'operation:'.$operation->id, 'workflow' => $definition['workflow'],
                    ]]])->save();
                }

                return $operation;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (($existing = $this->idempotency->replay($kind)) !== null) {
                return $existing;
            }
            if ($lockKey !== null) {
                $holder = Operation::query()->where('lock_key', $lockKey)->value('id');

                throw new OperationTargetBusy($kind, $targetType, $targetId, $nodeId, array_diff_key($input, array_flip($fields)),
                    $triggeredBy, $requestId, is_int($holder) ? $holder : null, $exception);
            }

            throw $exception;
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
        return $this->receipt($kind, $key, $fingerprint, [
            'target_type' => $targetType, 'target_id' => $targetId, 'node_id' => $nodeId,
            'input' => $input, 'triggered_by' => $triggeredBy, 'request_id' => $requestId,
            'status' => OperationStatus::Cancelled, 'error_code' => 'operation.admission_fenced',
            'result' => ['admission_fenced' => true],
        ]);
    }

    /**
     * Records a keyed start that the Instance hold refused as a definite failure before submission: no attempt, no
     * result, no hold and no native run. A replay of the key returns this receipt. Other targets keep no receipt.
     */
    public function refuseAdmission(OperationTargetBusy $refusal, string $key, string $fingerprint): ?Operation
    {
        if ($refusal->targetType !== OperationKinds::InstanceTarget) {
            return null;
        }

        return $this->receipt($refusal->kind, $key, $fingerprint, [
            'target_type' => $refusal->targetType, 'target_id' => $refusal->targetId, 'node_id' => $refusal->nodeId,
            'input' => $refusal->input, 'triggered_by' => $refusal->triggeredBy, 'request_id' => $refusal->requestId,
            'status' => OperationStatus::Failed, 'error_code' => 'operation.target_busy', 'result' => null,
        ]);
    }

    /**
     * One terminal no-effect receipt per kind and key. The unique kind/key constraint settles a race with a delayed
     * start; the winner's fingerprint is checked before returning either receipt.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function receipt(string $kind, string $key, string $fingerprint, array $attributes): Operation
    {
        try {
            $operation = Operation::query()->create([...$attributes,
                'kind' => $kind, 'idempotency_key' => $key, 'idempotency_fingerprint' => $fingerprint,
                'sequence' => 1, 'attempts' => 0, 'finished_at' => Carbon::now(),
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
     * Cancels a holder that was never submitted to Swamp. A submitted Operation counts as started and keeps its target.
     *
     * @param  array<string, mixed>  $changes
     */
    private function cancelUnstarted(Operation $operation, Carbon $now, array $changes = []): bool
    {
        return Operation::query()
            ->whereKey($operation->id)
            ->where('status', OperationStatus::Queued->value)
            ->where('attempts', 0)
            ->whereNull('dispatched_at')
            ->whereNull('state->swamp_run_id')
            ->update([
                ...$changes,
                'status' => OperationStatus::Cancelled->value,
                'lock_key' => null,
                'finished_at' => $now,
                'sequence' => DB::raw('sequence + 1'),
                'updated_at' => $now,
            ]) === 1;
    }

    /** @param array<string,mixed> $changes */
    public function change(Operation $operation, array $changes = []): void
    {
        $before = $operation->status;
        $cancelRequested = array_key_exists('cancel_requested_at', $changes) && $operation->cancel_requested_at === null;
        DB::transaction(function () use ($operation, $changes): void {
            $current = Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $operation->setRawAttributes($current->getAttributes(), true);
            $operation->forceFill([...$changes, 'sequence' => $current->sequence + 1])->save();
        });
        $this->broadcaster->changed($operation, $before !== $operation->status || $cancelRequested);
    }
}
