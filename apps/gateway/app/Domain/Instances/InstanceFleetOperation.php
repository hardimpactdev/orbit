<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Engine\Engine;
use App\Domain\Engine\EngineKinds;
use App\Domain\Engine\EngineRun;
use App\Domain\Instances\Power\InstanceStart;
use App\Domain\Operations\NativeRunCancellation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationRecordCoordinator;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `instance.fleet`: the wait for a nested-fleet clone's inner fleet, after its create (docs/reference/development-containers.md#a-clone-starts-its-fleet).
 * The create ends as soon as the clone's Conn Server answers; this Operation runs `instance.fleet_ready` on the engine beside the
 * completion and records the fleet's ready time, or its failure, on the Instance's runtime. It holds no lock, so the completion, a
 * destroy and a sleep never wait for it. A destroy or a sleep of the Instance cancels it, and its end writes nothing to an Instance
 * that a removal owns or that is gone.
 *
 * A wait that fails never leaves the fleet to nobody: its fallback, `instance.start`, checks and starts the fleet as Swamp does, once
 * the Instance is active and awake. Until a start proves the fleet ready, the runtime owes it (`fleet_start_pending`): a sleep keeps
 * that, and the next wake starts the fleet instead of resuming it. It runs only on the engine: it has no Swamp workflow, so the reconciler's pass reaches it through {@see sweep()}.
 */
final readonly class InstanceFleetOperation implements OperationRecordCoordinator
{
    /** How long an admitted wait may stay queued before the sweep starts it: its start after the commit was lost. */
    public const int LostStartSeconds = 60;

    public const string Kind = 'instance.fleet';

    /** The kinds whose admission cancels the Instance's open fleet wait: the copy goes, or it sleeps. */
    public const array Ends = ['instance.destroy', 'instance.sleep'];

    /**
     * The kinds whose admission makes the recorded fleet stale, and a waiting fallback needless: the copy starts, or its create runs
     * again. Each brings its own fleet start, and `instance.start` records its own end. A sleep and a wake keep the record: a freeze
     * keeps the boot, and a fleet that is still owed (`fleet_start_pending`) makes the wake an `instance.start`.
     */
    public const array Resets = ['instance.create', 'instance.start'];

    /** `state.fallback` of a failed wait whose fleet start waits for the Instance to be active. */
    public const string FallbackPending = 'pending';

    public function __construct(private OperationStore $operations) {}

    /** Whether a clone's fleet can wait on the engine: the engine runs this kind and its Node reports `instance.fleet_ready`. */
    public static function runs(Operation $create): bool
    {
        return app(EngineKinds::class)->routes(self::draft($create));
    }

    /** What the admission of `$kind` for the Instance changes here (OperationStore::create): a cancel, a reset, or both. */
    public static function admitted(string $kind, int $instanceId): void
    {
        if (in_array($kind, self::Ends, true)) {
            self::cancelFor($instanceId);
        }
        if (in_array($kind, self::Resets, true)) {
            InstanceRuntime::query()->where('instance_id', $instanceId)->update(['fleet_ready_at' => null, 'fleet_failed_at' => null, 'fleet_error_code' => null]);
            foreach (self::pending()->where('target_id', $instanceId)->get() as $waiting) {
                app(OperationStore::class)->change($waiting, ['state' => [...($waiting->state ?? []), 'fallback' => 'superseded']]);
            }
        }
    }

    /**
     * Admits the wait for the clone that `$create` made, and starts it on the engine once the caller's transaction commits. A failed
     * admission leaves the fleet to the completion: the create's result loses `fleet_start`, so the completion, which reads it at its
     * start after this commit, runs `clone-fleet`.
     */
    public function admit(Operation $create): ?Operation
    {
        try {
            // An admission the request did not ask for: it takes no idempotency key of the request.
            $operation = DB::transaction(function () use ($create): Operation {
                $operation = app(OperationIdempotency::class)->suspended(fn (): Operation => $this->operations->create(
                    self::Kind, 'instance', (int) $create->target_id, $create->node_id, self::draft($create)->input, $create->triggered_by, $create->request_id));
                // The fleet is owed until a start proves it ready: a sleep before then makes the next wake start it.
                InstanceRuntime::query()->where('instance_id', $create->target_id)->update(['fleet_start_pending' => true]);

                return $operation;
            });
        } catch (Throwable $error) {
            report($error);
            $this->operations->change($create, ['result' => array_diff_key($create->result ?? [], ['fleet_start' => true])]);

            return null;
        }
        $id = $operation->id;
        DB::afterCommit(static fn () => app(self::class)->start($id));

        return $operation;
    }

    /** Starts an admitted wait on the engine, as a Swamp start binds its run. */
    public function start(int $id): void
    {
        $operation = DB::transaction(function () use ($id): ?Operation {
            $operation = Operation::query()->whereKey($id)->lockForUpdate()->first();
            if ($operation === null || $operation->status !== OperationStatus::Queued) {
                return null;
            }
            if ($operation->cancel_requested_at !== null) {
                $this->operations->change($operation, ['status' => OperationStatus::Cancelled, 'finished_at' => now(), 'error_code' => 'operation.cancelled']);

                return null;
            }
            $this->operations->change($operation, ['status' => OperationStatus::Running, 'started_at' => now(), 'dispatched_at' => now(),
                'state' => [...($operation->state ?? []), 'swamp_run_id' => EngineRun::runIdOf($operation->id)]]);

            return $operation->refresh();
        });
        if ($operation === null) {
            return;
        }
        try {
            app(Engine::class)->start($operation);
        } catch (Throwable $error) {
            report($error);
            // Steps that were recorded before a lost publish get the sweep's nudge; a wait with none cannot run.
            if (! OperationEngineStep::query()->where('operation_id', $id)->exists()) {
                $this->close($operation, OperationStatus::Failed, 'engine.start_failed');
            }
        }
    }

    /**
     * Ends an open wait, once: a requested cancel wins over what the Node answered, and records nothing. A ready fleet records
     * `fleet_ready_at`; a failure records `fleet_failed_at` with its code, then its fallback starts the fleet.
     */
    public function close(Operation $operation, OperationStatus $status, ?string $code, ?string $failedStep = null): void
    {
        $closed = DB::transaction(function () use ($operation, $status, $code, $failedStep): ?Operation {
            // The Instance first, then the wait: the order of a sleep's or a destroy's admission, so neither waits on the other's lock.
            Instance::query()->whereKey($operation->target_id)->lockForUpdate()->first();
            $current = Operation::query()->whereKey($operation->id)->lockForUpdate()->first();
            if ($current === null || $current->status->terminal()) {
                return null;
            }
            [$status, $code, $failedStep] = $current->cancel_requested_at !== null ? [OperationStatus::Cancelled, 'operation.cancelled', null] : [$status, $code, $failedStep];
            $failed = $status === OperationStatus::Failed;
            $this->operations->change($current, ['status' => $status, 'finished_at' => now(), 'current_step' => null, 'error_code' => $code,
                'failed_step' => $failedStep,
                'state' => [...($current->state ?? []), ...($failed ? ['fallback' => self::FallbackPending] : [])],
                'result' => ['instance_id' => $current->target_id, 'fleet' => $status === OperationStatus::Succeeded ? 'ready' : ($code ?? 'cancelled')]]);
            // Under the locks, so a start admitted after this end clears what it wrote, never the other way round.
            if ($status !== OperationStatus::Cancelled) {
                self::record((int) $current->target_id, $failed ? $code : null);
            }

            return $current->refresh();
        });
        if ($closed?->status === OperationStatus::Failed) {
            $this->fallback($closed->id);
        }
    }

    /**
     * Starts the fleet of a failed wait as Swamp does (`instance.start`: the fleet check, then its resume and Doctor wait), once the
     * Instance is active and free. Until then the wait stays pending for the sweep. A removal, or another start of the fleet, ends it.
     */
    public function fallback(int $id): void
    {
        $operation = Operation::query()->find($id);
        if ($operation === null || ($operation->state['fallback'] ?? null) !== self::FallbackPending) {
            return;
        }
        $instance = Instance::query()->with('runtime')->find($operation->target_id);
        // Nothing to start once the copy goes, or once its completion failed: the completion's retry starts the fleet itself
        // (NativeInstanceCompleteInput), and the owed fleet stays owed for the next wake.
        if ($instance === null || $instance->status === InstanceState::Removing || self::completionFailed($instance)) {
            $this->operations->change($operation, ['state' => [...($operation->state ?? []), 'fallback' => 'dropped']]);

            return;
        }
        // The completion still runs; or the copy sleeps, and its wake starts the owed fleet (InstanceWake).
        if ($instance->status !== InstanceState::Active || $instance->runtime?->power_state === InstanceRuntimePowerState::Stopped) {
            return;
        }
        try {
            $start = app(OperationIdempotency::class)->suspended(fn (): Operation => app(InstanceStart::class)->start($instance, $operation->triggered_by, $operation->request_id));
        } catch (ResourceOperationException $refusal) {
            // Another Operation holds the Instance: the next pass tries again. A clone that cannot start this way has nothing to try.
            if ($refusal->errorCode === 'instance.start_unsupported') {
                $this->operations->change($operation, ['state' => [...($operation->state ?? []), 'fallback' => 'dropped']]);
            }

            return;
        }
        $operation->refresh();
        $this->operations->change($operation, ['state' => [...($operation->state ?? []), 'fallback' => $start->id]]);
    }

    /**
     * The reconciler's pass: a start lost after its commit runs now, a wait past its budget ends as timed out, a stalled run gets the
     * engine's nudge, and a failed wait's fleet start is admitted once the Instance is active.
     */
    public function sweep(): void
    {
        $open = Operation::query()->where('kind', self::Kind)->whereIn('status', [OperationStatus::Queued, OperationStatus::Running])->get();
        foreach ($open as $operation) {
            try {
                $deadline = OperationRunBudgets::deadline($operation);
                if ($deadline !== null && ! $deadline->isFuture()) {
                    $this->close($operation, OperationStatus::Failed, 'operation.timed_out');
                } elseif ($operation->status === OperationStatus::Queued && $operation->created_at?->lte(now()->subSeconds(self::LostStartSeconds))) {
                    $this->start($operation->id);
                } elseif (Engine::owns($operation)) {
                    app(Engine::class)->nudgeIfStalled($operation);
                }
            } catch (Throwable $error) {
                report($error);
            }
        }
        foreach (self::pending()->pluck('id')->map(static fn (mixed $id): int => ValidatedData::integer($id)) as $id) {
            try {
                $this->fallback($id);
            } catch (Throwable $error) {
                report($error);
            }
        }
    }

    /** Cancels the Instance's open fleet wait: a destroy or a sleep of the Instance was admitted. */
    public static function cancelFor(int $instanceId): void
    {
        $open = Operation::query()->where('kind', self::Kind)->where('target_type', 'instance')->where('target_id', $instanceId)
            ->whereNotIn('status', OperationStatus::terminalValues())->get();
        foreach ($open as $operation) {
            app(OperationStore::class)->change($operation, ['cancel_requested_at' => $operation->cancel_requested_at ?? now()]);
            if (Engine::owns($operation)) {
                app(NativeRunCancellation::class)->request($operation->refresh());
            }
        }
    }

    /** Whether the Instance has an open fleet wait. */
    public static function open(int $instanceId): bool
    {
        return Operation::query()->where('kind', self::Kind)->where('target_type', 'instance')->where('target_id', $instanceId)
            ->whereNotIn('status', OperationStatus::terminalValues())->exists();
    }

    /** Whether the Instance's latest completion ended without success: it is not active, and no completion is open. */
    private static function completionFailed(Instance $instance): bool
    {
        $completion = Operation::query()->where('kind', 'instance.complete')->where('target_type', 'instance')->where('target_id', $instance->id)
            ->latest('id')->first();

        return $instance->status !== InstanceState::Active && $completion !== null && $completion->status->terminal()
            && $completion->status !== OperationStatus::Succeeded;
    }

    /**
     * Records the fleet's end on the Instance's runtime, unless a removal owns the Instance or it is gone: `fleet_ready_at`, or
     * `fleet_failed_at` with the code.
     */
    public static function record(int $instanceId, ?string $failure): void
    {
        DB::transaction(static function () use ($instanceId, $failure): void {
            $instance = Instance::query()->lockForUpdate()->find($instanceId);
            if ($instance === null || $instance->status === InstanceState::Removing) {
                return;
            }
            // Only a ready fleet pays what is owed; a failed one stays owed for the fallback or the next wake.
            $instance->runtime()->first()?->update($failure === null
                ? ['fleet_ready_at' => now(), 'fleet_failed_at' => null, 'fleet_error_code' => null, 'fleet_start_pending' => false]
                : ['fleet_ready_at' => null, 'fleet_failed_at' => now(), 'fleet_error_code' => $failure]);
        });
    }

    /** @return Builder<Operation> Failed waits whose fallback waits for the Instance. */
    private static function pending(): Builder
    {
        return Operation::query()->where('kind', self::Kind)->where('target_type', 'instance')->where('status', OperationStatus::Failed)
            ->where('state->fallback', self::FallbackPending);
    }

    /** The wait as it would be admitted: its target, Node and input. */
    private static function draft(Operation $create): Operation
    {
        $container = ValidatedData::object(ValidatedData::object($create->input['direct_provisioning'] ?? [])['container'] ?? []);

        return (new Operation)->forceFill(['kind' => self::Kind, 'target_type' => 'instance', 'target_id' => $create->target_id,
            'node_id' => $create->node_id, 'input' => ['instance_id' => $create->target_id, 'create_operation_id' => $create->id,
                'project' => $container['project'] ?? null, 'name' => $container['name'] ?? null]]);
    }
}
