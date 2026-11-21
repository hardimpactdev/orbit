<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Engine\Engine;
use App\Domain\Engine\EngineKinds;
use App\Domain\Engine\EngineRun;
use App\Domain\Operations\NativeRunCancellation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationRecordCoordinator;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `instance.fleet`: the wait for a nested-fleet clone's inner fleet, after its create (docs/reference/development-containers.md#a-clone-starts-its-fleet).
 * The create ends as soon as the clone's Conn Server answers; this Operation runs `instance.fleet_ready` on the engine beside the
 * completion and records the fleet's ready time, or its failure, on the Instance's runtime. It holds no lock, so the completion, a
 * destroy and a sleep never wait for it. A destroy or a sleep of the Instance cancels it, and its end writes nothing to an Instance
 * that a removal owns or that is gone. It runs only on the engine: it has no Swamp workflow, so the reconciler's pass reaches it
 * through {@see sweep()}.
 */
final readonly class InstanceFleetOperation implements OperationRecordCoordinator
{
    /** How long an admitted wait may stay queued before the sweep starts it: its start after the commit was lost. */
    public const int LostStartSeconds = 60;

    public const string Kind = 'instance.fleet';

    /** The kinds whose admission cancels the Instance's open fleet wait: the copy goes, or it sleeps. */
    public const array Ends = ['instance.destroy', 'instance.sleep'];

    public function __construct(private OperationStore $operations) {}

    /** Whether a clone's fleet can wait on the engine: the engine runs this kind and its Node reports `instance.fleet_ready`. */
    public static function runs(Operation $create): bool
    {
        return app(EngineKinds::class)->routes(self::draft($create));
    }

    /**
     * Admits the wait for the clone that `$create` made, and starts it on the engine once the caller's transaction commits. A failed
     * admission records the fleet as failed: the Instance stays usable, and its show names why.
     */
    public function admit(Operation $create): ?Operation
    {
        try {
            // An admission the request did not ask for: it takes no idempotency key of the request.
            $operation = DB::transaction(fn (): Operation => app(OperationIdempotency::class)->suspended(fn (): Operation => $this->operations->create(
                self::Kind, 'instance', (int) $create->target_id, $create->node_id, self::draft($create)->input, $create->triggered_by, $create->request_id)));
        } catch (Throwable $error) {
            report($error);
            Instance::query()->find($create->target_id)?->runtime()->first()
                ?->update(['fleet_failed_at' => now(), 'fleet_error_code' => 'operation.admission_failed']);

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
                $this->end($operation, 'engine.start_failed');
            }
        }
    }

    /**
     * The reconciler's pass over open waits: a start lost after its commit runs now, a wait past its budget ends as timed out, and
     * a stalled run gets the engine's nudge.
     */
    public function sweep(): void
    {
        $open = Operation::query()->where('kind', self::Kind)->whereIn('status', [OperationStatus::Queued, OperationStatus::Running])->get();
        foreach ($open as $operation) {
            try {
                $deadline = OperationRunBudgets::deadline($operation);
                if ($deadline !== null && ! $deadline->isFuture()) {
                    $this->end($operation, 'operation.timed_out');
                } elseif ($operation->status === OperationStatus::Queued && $operation->created_at?->lte(now()->subSeconds(self::LostStartSeconds))) {
                    $this->start($operation->id);
                } elseif (Engine::owns($operation)) {
                    app(Engine::class)->nudgeIfStalled($operation);
                }
            } catch (Throwable $error) {
                report($error);
            }
        }
    }

    /** Ends an open wait as failed, and records it as {@see record()} does; one that ended meanwhile stays as it is. */
    private function end(Operation $operation, string $code): void
    {
        $ended = DB::transaction(function () use ($operation, $code): bool {
            $current = Operation::query()->whereKey($operation->id)->lockForUpdate()->first();
            if ($current === null || $current->status->terminal()) {
                return false;
            }
            $this->operations->change($current, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'current_step' => null, 'error_code' => $code,
                'result' => ['instance_id' => $current->target_id, 'fleet' => $code]]);

            return true;
        });
        if ($ended) {
            self::record((int) $operation->target_id, $code);
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

    /**
     * Records the wait's end on the Instance's runtime, unless a removal owns the Instance or it is gone: `fleet_ready_at`, or
     * `fleet_failed_at` with the code. A cancelled wait records nothing.
     */
    public static function record(int $instanceId, ?string $failure): void
    {
        DB::transaction(static function () use ($instanceId, $failure): void {
            $instance = Instance::query()->lockForUpdate()->find($instanceId);
            if ($instance === null || $instance->status === InstanceState::Removing) {
                return;
            }
            $instance->runtime()->first()?->update($failure === null
                ? ['fleet_ready_at' => now(), 'fleet_failed_at' => null, 'fleet_error_code' => null]
                : ['fleet_ready_at' => null, 'fleet_failed_at' => now(), 'fleet_error_code' => $failure]);
        });
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
