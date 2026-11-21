<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\InstanceMigration\InstanceMigrateProjection;
use App\Domain\InstanceMigration\InstanceMigration;
use App\Domain\Instances\Deployment\InstanceDeployExecutionCopies;
use App\Domain\Instances\InstanceFleetOperation;
use App\Domain\Instances\Power\ExecutorFence;
use App\Domain\Nodes\NodeConvergePhaseDigest;
use App\Domain\Proof\ProofSeeds;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskLifecycle;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\Operation;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
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
    public function create(string $kind, string $targetType, int $targetId, ?int $nodeId, array $input, ?string $triggeredBy, ?string $requestId, ?string $lockKey = null, ?Operation $parent = null): Operation
    {
        $parent ??= $this->idempotency->parent($kind);
        if ($this->idempotency->key !== null && $this->idempotency->kind !== $kind) {
            throw new LogicException('The Operation kind must match the request idempotency key.');
        }
        if (($existing = $this->idempotency->replay($kind, $parent)) !== null) {
            return $existing;
        }
        $gatewayJob = OperationKinds::gatewayJob($kind);
        OperationKinds::executor($kind);
        if ($nodeId === null) {
            throw new ResourceOperationException('operation.node_required', 'A direct Operation requires its admitted root Node.', 422);
        }
        try {
            $operation = DB::transaction(function () use ($kind, $targetType, $targetId, $nodeId, $input, $triggeredBy, $requestId, $gatewayJob, $lockKey, $parent): Operation {
                $beside = app(NodeWorkAdmission::class)->check($kind, $nodeId, $input, $parent?->id);
                $direct = app(DirectOperation::class);
                $version = $parent === null ? OperationVersions::admit($kind) : $this->childVersion($parent, $kind, $input);
                $definition = $gatewayJob ? [] : ($direct->definition($kind, $version) ?? throw new ResourceOperationException('operation.version_unavailable', 'This Gateway does not have the pinned Operation handler.', 503));
                $fields = array_values(array_unique([...array_keys($definition['private_material'] ?? []), ...array_keys($definition['secret_outputs'] ?? [])]));
                app(DirectOperationPrivateBindings::class)->assertAdmission($input, $fields, $nodeId);
                $children = $direct->childVersions($kind, $version);
                if ($parent === null) {
                    $this->assertChildrenAdmissible($direct, $kind, $version);
                }
                $operation = Operation::query()->create([
                    'operation_version' => $version, 'child_versions' => $children, 'parent_operation_id' => $parent?->id,
                    'kind' => $kind, 'idempotency_key' => $this->idempotency->key, 'idempotency_fingerprint' => $this->idempotency->fingerprint,
                    'target_type' => $targetType, 'target_id' => $targetId, 'node_id' => $nodeId, 'lock_key' => $lockKey,
                    'input' => $input, 'state' => $beside === [] ? [] : ['admitted_beside' => $beside], 'status' => OperationStatus::Queued, 'sequence' => 1, 'attempts' => 0,
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
            if (($existing = $this->idempotency->replay($kind, $parent)) !== null) {
                return $existing;
            }
            if ($lockKey !== null) {
                throw new ResourceOperationException('operation.target_busy', 'Another Operation owns this target.', 409);
            }

            throw $exception;
        } catch (QueryException $error) {
            if (! str_contains($error->getMessage(), 'operation.version_deprecated')) {
                throw $error;
            }

            return $this->idempotency->replay($kind, $parent) ?? throw OperationVersions::deprecated();
        }
        // A destroy or a sleep of an Instance ends its fleet wait; a start or a new create makes its recorded fleet stale. A sleep and a
        // wake keep it: a freeze keeps the boot (InstanceFleetOperation::Resets).
        if ($targetType === OperationKinds::InstanceTarget) {
            InstanceFleetOperation::admitted($kind, $targetId);
        }
        $this->broadcaster->created($operation);

        return $operation;
    }

    /** @param array<string,mixed> $input */
    private function childVersion(Operation $parent, string $kind, array $input): int
    {
        $record = Operation::query()->lockForUpdate()->findOrFail($parent->id);
        $children = app(DirectOperation::class)->pinnedChildVersions($record);
        $retry = Arr::get($record->state ?? [], 'launch_retry_requests.application.id');
        $applicationRetry = $record->kind === OperationKinds::ConnLaunch && $record->status === OperationStatus::Succeeded && $kind === 'instance.complete'
            && is_string($retry) && $this->idempotency->key === 'conn-launch:'.$record->id.':complete:'.$retry;
        if (($record->status->terminal() && ! $applicationRetry) || ! isset($children[$kind])
            || ($kind === 'proof.command' && ($input['proof_operation_id'] ?? null) !== $record->id)
            || (in_array($kind, [OperationKinds::ConnLaunchInspect, OperationKinds::ConnLaunchDispatch], true) && ($input['launch_operation_id'] ?? null) !== $record->id)) {
            throw new ResourceOperationException('operation.child_unadmitted', 'The open parent has no admission for this child Operation.', 409);
        }

        return $children[$kind];
    }

    /** @param list<string> $path */
    private function assertChildrenAdmissible(DirectOperation $direct, string $kind, int $version, array $path = []): void
    {
        if (in_array($kind.'@'.$version, $path, true)) {
            throw new LogicException('A versioned Operation cannot depend on itself.');
        }
        foreach ($direct->childVersions($kind, $version) as $child => $pinned) {
            OperationVersions::assertAdmissible($child, $pinned);
            $this->assertChildrenAdmissible($direct, $child, $pinned, [...$path, $kind.'@'.$version]);
        }
    }

    /**
     * Records a terminal no-effect receipt if admission is absent. The unique kind/key constraint settles a race
     * with a delayed start; the winner's fingerprint is checked before returning either receipt.
     *
     * @param  array<string, mixed>  $input
     */
    public function fenceAdmission(string $kind, string $key, string $fingerprint, string $targetType, int $targetId, ?int $nodeId, array $input, string $triggeredBy, string $requestId): Operation
    {
        if (($existing = $this->fenceReceipt($kind, $key, $fingerprint)) !== null) {
            return $existing;
        }
        try {
            $operation = Operation::query()->create([
                'kind' => $kind, 'operation_version' => OperationVersions::admit($kind), 'idempotency_key' => $key, 'idempotency_fingerprint' => $fingerprint,
                'target_type' => $targetType, 'target_id' => $targetId, 'node_id' => $nodeId,
                'input' => $input, 'triggered_by' => $triggeredBy, 'request_id' => $requestId,
                'status' => OperationStatus::Cancelled, 'sequence' => 1, 'attempts' => 0,
                'finished_at' => Carbon::now(), 'error_code' => 'operation.admission_fenced',
                'result' => ['admission_fenced' => true],
            ]);
        } catch (QueryException $exception) {
            if ($exception instanceof UniqueConstraintViolationException || str_contains($exception->getMessage(), 'operation.version_deprecated')) {
                return $this->fenceReceipt($kind, $key, $fingerprint) ?? throw ($exception instanceof UniqueConstraintViolationException ? $exception : OperationVersions::deprecated());
            }

            throw $exception;
        }
        $this->broadcaster->created($operation);

        return $operation;
    }

    /** @phpstan-impure Reads the receipt another admission may have committed. */
    private function fenceReceipt(string $kind, string $key, string $fingerprint): ?Operation
    {
        $operation = Operation::query()->where('kind', $kind)->where('idempotency_key', $key)->first();
        if ($operation !== null && $operation->idempotency_fingerprint !== $fingerprint) {
            throw new ResourceOperationException('operation.idempotency_conflict', 'The request key belongs to another caller, command, Instance, or input.', 409);
        }

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
            if ($status instanceof OperationStatus && $status->terminal()) {
                $changes = ExecutorFence::settle($current, $changes);
            }
            $operation->forceFill([...$changes, 'sequence' => $current->sequence + 1])->save();
            if ($operation->kind === InstanceMigration::Kind && ! $current->status->terminal() && $operation->status->terminal()) {
                app(InstanceMigrateProjection::class)->terminal($operation, $current);
            }
            // Every end of a create or of a clone's completion reaches here: a run's report, a refused start, a cancel before the run, an
            // expired budget. A task's copy moves its group on when it is active or failed; an Instance that no task owns changes nothing.
            if (in_array($operation->kind, ['instance.create', 'instance.complete'], true) && ! $current->status->terminal() && $operation->status->terminal()) {
                app(TaskLifecycle::class)->createEnded($operation);
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
