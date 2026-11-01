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

    /** @param array<string,mixed> $input */
    public function create(string $kind, string $targetType, int $targetId, ?int $nodeId, array $input, ?string $triggeredBy, ?string $requestId, ?string $lockKey = null): Operation
    {
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
        try {
            $operation = DB::transaction(function () use ($kind, $targetType, $targetId, $nodeId, $input, $triggeredBy, $requestId, $definition, $lockKey): Operation {
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
                throw new ResourceOperationException('operation.target_busy', 'Another Operation owns this target.', 409);
            }

            throw $exception;
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
