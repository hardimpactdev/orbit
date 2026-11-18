<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\InstanceMigration\InstanceMigrateProjection;
use App\Domain\InstanceMigration\InstanceMigration;
use App\Domain\Instances\Deployment\InstanceDeployExecutionCopies;
use App\Domain\Instances\Power\ExecutorWake;
use App\Domain\Nodes\NodeConvergePhaseDigest;
use App\Domain\Proof\ProofSeeds;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCopyLifecycle;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\Operation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

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
                app(NodeWorkAdmission::class)->check($kind, $nodeId, $input);
                $operation = Operation::query()->create([
                    'kind' => $kind, 'idempotency_key' => $this->idempotency->key, 'idempotency_fingerprint' => $this->idempotency->fingerprint,
                    'target_type' => $targetType, 'target_id' => $targetId, 'node_id' => $nodeId, 'lock_key' => $lockKey,
                    'input' => $input, 'state' => [], 'status' => OperationStatus::Queued, 'sequence' => 1, 'attempts' => 0,
                    'triggered_by' => $triggeredBy, 'request_id' => $requestId,
                ]);
                // Every Operation under the Node's admission lock may change what a converge phase applied, so the next converge runs every
                // phase (docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change).
                if ($lockKey !== null && str_starts_with($lockKey, 'node:')) {
                    app(NodeConvergePhaseDigest::class)->clear((int) substr($lockKey, 5));
                }
                if (isset($definition['workflow'])) {
                    $operation->forceFill(['state' => [...($operation->state ?? []), 'swamp_start' => [
                        'key' => 'operation:'.$operation->id, 'workflow' => app(DirectOperation::class)->workflowFor($operation),
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
    public function change(Operation $operation, array $changes = [], bool $onlyWhileOpen = false): void
    {
        $before = $operation->status;
        $cancelRequested = array_key_exists('cancel_requested_at', $changes) && $operation->cancel_requested_at === null;
        $written = DB::transaction(function () use ($operation, $changes, $onlyWhileOpen, $before, $cancelRequested): bool {
            $current = Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $operation->setRawAttributes($current->getAttributes(), true);
            if ($onlyWhileOpen && $current->status->terminal()) {
                return false;
            }
            $status = $changes['status'] ?? null;
            if ($status instanceof OperationStatus && $status->terminal() && ExecutorWake::requiresReceipt($current)) {
                $changes = [...$changes, 'status' => OperationStatus::Interrupted, 'lock_key' => $current->lock_key,
                    'error_code' => 'executor.result_unknown'];
            }
            $operation->forceFill([...$changes, 'sequence' => $current->sequence + 1])->save();
            if ($operation->kind === InstanceMigration::Kind && ! $current->status->terminal() && $operation->status->terminal()) {
                app(InstanceMigrateProjection::class)->terminal($operation, $current);
            }
            // Every end of a create or of a clone's completion reaches here: a run's report, a refused start, a cancel before the run, an
            // expired budget. A task's copy moves its group on when it is active or failed; an Instance that no task owns changes nothing.
            if (in_array($operation->kind, ['instance.create', 'instance.complete'], true) && ! $current->status->terminal() && $operation->status->terminal()) {
                app(TaskCopyLifecycle::class)->createEnded($operation);
            }
            if (is_int($operation->state['conn_launch_owner'] ?? null)) {
                $settlement = app(NativeRunCancellation::class);
                if (! OperationBroadcaster::usesWakeOutbox($operation) && $settlement->settled($operation) && ! $settlement->settled($current)) {
                    ReconcileSwampOperations::dispatch()->afterCommit();
                }
            }
            // Every end of a deploy reaches here too: a run's report, a refused start, a cancel before the run, an unreadable result, and
            // the settlement of a timed-out run. Its copies then leave the vault; a default is never destroyed, so nothing else removes them.
            if (InstanceDeployExecutionCopies::ended($operation) && ! InstanceDeployExecutionCopies::ended($current)) {
                DB::afterCommit(static function () use ($operation): void {
                    try {
                        app(InstanceDeployExecutionCopies::class)->remove($operation);
                    } catch (Throwable $exception) {
                        // The deploy's end stands; `orbit:instance-deploy-copies-prune` deletes what is left.
                        Log::warning('Deploy execution copies could not be deleted', ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
                    }
                });
            }
            // The same ends free a deployment's claim on the default's seed; a seed copy that the claim refused is admitted now.
            if (ProofSeeds::claimFreed($current, $operation)) {
                DB::afterCommit(static fn (): ?Operation => app(ProofSeeds::class)->admit('seed'));
            }
            if (OperationBroadcaster::usesWakeOutbox($operation)) {
                $this->broadcaster->changed($operation, $before !== $operation->status || $cancelRequested);
            }

            return true;
        });
        if (! $written) {
            return;
        }
        if (! OperationBroadcaster::usesWakeOutbox($operation)) {
            $this->broadcaster->changed($operation, $before !== $operation->status || $cancelRequested);
        }
        // The terminal state frees the Nodes of a Route write; a timed-out one keeps them until Swamp confirms that its run stopped.
        if ($operation->status->terminal() && RoutePublicationHolds::tracks($operation->kind) && ! RoutePublicationHolds::keepsNodes($operation)) {
            app(RoutePublicationHolds::class)->release($operation->id, $before === OperationStatus::Waiting);
        }
    }
}
