<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\StartInstanceCompleteAction;
use App\Domain\Engine\Engine;
use App\Domain\Engine\EngineRun;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationRecordCoordinator;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `instance.finalize`: a reserved clone takes its final name and branch when its user submits (docs/reference/conn.md#reserve-a-clone).
 * The clone runs already, keyed by its id; its admission renames the Instance at once, so the name is held, and the engine then moves
 * the branch, writes the names in the copy and restarts T3 for its label ({@see InstanceFinalizeEngineFlow}). Its success records the
 * branch and the T3 server's label and admits the clone's completion, which the reservation deferred. It holds the Instance lock and
 * runs only on the engine.
 */
final readonly class InstanceFinalize implements OperationRecordCoordinator
{
    /** How long an admitted finalize may stay queued before the sweep starts it: its start after the commit was lost. */
    public const int LostStartSeconds = 60;

    public const string Kind = 'instance.finalize';

    /** How long T3 may take to answer with its new label after its restart. */
    public const int LabelSeconds = 30;

    public function __construct(private OperationStore $operations) {}

    /**
     * Admits the finalize of `$instance`, whose create succeeded and deferred its completion, under the name `$name` and, with a
     * branch selection, on its branch. The caller checked the name and prepared the selection (DevelopmentInstanceBranchAdmission).
     * Call it inside the caller's transaction; the run starts after the commit.
     */
    public function admit(Instance $instance, string $name, ?InstanceBranchSelection $selection, ?string $caller, ?string $requestId): Operation
    {
        $instance = Instance::query()->with(['project', 'node'])->lockForUpdate()->findOrFail($instance->id);
        $create = Operation::query()->where('kind', 'instance.create')->where('target_type', 'instance')->where('target_id', $instance->id)->latest('id')->first();
        if ($instance->status !== InstanceState::SourceResolved || $create?->status !== OperationStatus::Succeeded
            || ($create->input['completion_deferred'] ?? false) !== true) {
            throw new ResourceOperationException('instance.finalize_unavailable', 'Only a reserved clone whose create succeeded takes its final name.', 409);
        }
        if (Instance::query()->where('project_id', $instance->project_id)->where('name', $name)->whereKeyNot($instance->id)->exists()) {
            throw new ResourceOperationException('instance.name_taken', 'Another Instance holds this name.', 409);
        }
        if ($selection !== null) {
            $selection = app(DevelopmentInstanceBranchAdmission::class)->reserve($instance->project, $instance->project, $selection, $instance, $caller);
        }
        $project = $instance->project;
        $from = IntendedInstanceBranch::forInstance($instance, $project->default_branch);
        $instance->forceFill(['name' => $name, 'branch_override' => $selection?->target])->save();
        $runtime = $instance->runtime()->firstOrFail();
        // The renamed Instance's own Instance file and T3 label, as a create renders them.
        $clone = ValidatedData::object(app(InstanceContainer::class)->intent($instance->refresh(), $runtime)['clone'] ?? []);
        $container = ValidatedData::object($create->input['direct_provisioning'])['container'] ?? [];
        $files = is_array($clone['files'] ?? null) ? $clone['files'] : [];
        $file = array_values(array_filter($files, static fn (mixed $file): bool => is_array($file)
            && ($file['path'] ?? null) === InstanceContainer::InstanceFile))[0]['content'] ?? null;
        $input = ['instance_id' => $instance->id, 'project_id' => $project->id, 'name' => $name, 'create_operation_id' => $create->id,
            'repository' => $project->repository_url, 'repository_identity' => $project->repository_identity,
            'container' => ['project' => ValidatedData::string(ValidatedData::object($container)['project'] ?? null),
                'name' => ValidatedData::string(ValidatedData::object($container)['name'] ?? null),
                'workspace' => ValidatedData::string(ValidatedData::object($container)['workspace'] ?? null),
                'group' => ValidatedData::integer(ValidatedData::object(ValidatedData::object($container)['workspace_ids'] ?? [])['group'] ?? null),
                'mirror' => ValidatedData::string(ValidatedData::object($container)['mirror'] ?? null),
                'protocol' => ValidatedData::stringList(ValidatedData::object($container)['protocols'] ?? [])[0] ?? 'https'],
            'machine_name' => InstanceMachineName::intent($instance), 'instance_file' => ValidatedData::string($file),
            'label' => ValidatedData::string(ValidatedData::object($clone['t3'] ?? [])['label'] ?? null),
            'branch' => $selection === null ? ['mode' => 'rename', 'from' => $from, 'to' => $name] : ['mode' => 'switch', 'selection' => $selection->toArray()]];
        // The request's Idempotency-Key, when it has one, keys the finalize.
        $operation = $this->operations->create(self::Kind, 'instance', $instance->id, $instance->node_id, $input, $caller, $requestId,
            lockKey: OperationStore::lockKey('instance', $instance->id));
        $id = $operation->id;
        DB::afterCommit(static fn () => app(self::class)->start($id));

        return $operation;
    }

    /** Starts an admitted finalize on the engine, as a Swamp start binds its run. */
    public function start(int $id): void
    {
        $operation = DB::transaction(function () use ($id): ?Operation {
            $operation = Operation::query()->whereKey($id)->lockForUpdate()->first();
            if ($operation === null || $operation->status !== OperationStatus::Queued) {
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
            if (! OperationEngineStep::query()->where('operation_id', $id)->exists()) {
                $this->close($operation, null, 'engine.start_failed', null);
            }
        }
    }

    /**
     * The reconciler's pass over open finalizes, which hold the Instance lock: a start lost after its commit runs now, one past its
     * budget ends as timed out and lets go of the lock, and a stalled run gets the engine's nudge.
     */
    public function sweep(): void
    {
        foreach (Operation::query()->where('kind', self::Kind)->whereIn('status', [OperationStatus::Queued, OperationStatus::Running])->get() as $operation) {
            try {
                $deadline = OperationRunBudgets::deadline($operation);
                if ($deadline !== null && ! $deadline->isFuture()) {
                    $this->close($operation, null, 'operation.timed_out', null);
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

    /**
     * Ends the finalize once. A success records the branch and the T3 server's label and admits the deferred completion, in one
     * transaction after the finalize let go of the Instance lock. A failure keeps the clone as it is, with its new name, for a retry
     * or a removal.
     *
     * @param  array<string, mixed>|null  $names  the `names` step's output
     */
    public function close(Operation $operation, ?array $names, ?string $code, ?string $failedStep): void
    {
        DB::transaction(function () use ($operation, $names, $code, $failedStep): void {
            $instance = Instance::query()->lockForUpdate()->find($operation->target_id);
            $current = Operation::query()->whereKey($operation->id)->lockForUpdate()->first();
            if ($current === null || $current->status->terminal()) {
                return;
            }
            $succeeded = $names !== null && $code === null && $current->cancel_requested_at === null && $instance !== null
                && $instance->status !== InstanceState::Removing;
            $status = $succeeded ? OperationStatus::Succeeded : ($current->cancel_requested_at !== null ? OperationStatus::Cancelled : OperationStatus::Failed);
            $code = $succeeded ? null : ($code ?? ($status === OperationStatus::Cancelled ? 'operation.cancelled' : 'instance.finalize_failed'));
            $this->operations->change($current, ['status' => $status, 'finished_at' => now(), 'current_step' => null, 'error_code' => $code,
                'failed_step' => $failedStep, 'lock_key' => null,
                'result' => ['instance_id' => $current->target_id, 'name' => $current->input['name'] ?? null,
                    ...($succeeded ? ['branch' => $names['branch'] ?? null, 'commit' => $names['commit'] ?? null, 'restart_ms' => $names['restart_ms'] ?? null,
                        ...(is_bool($names['reloaded'] ?? null) ? ['reloaded' => $names['reloaded']] : [])] : [])]]);
            if (! $succeeded) {
                return;
            }
            $instance->forceFill(['branch' => ValidatedData::nullableString($names['branch'] ?? null)])->save();
            app(InstanceLifecycleContributions::class)->projectRename($instance, ValidatedData::string($current->input['label'] ?? null));
            // The completion the reservation deferred: the database and the Route now take the final name.
            $completion = app(StartInstanceCompleteAction::class);
            try {
                $admitted = DB::transaction(fn (): Operation => app(OperationIdempotency::class)->suspended(
                    fn (): Operation => $completion->admit($instance->refresh(), $current->triggered_by, $current->request_id)));
            } catch (ResourceOperationException $refusal) {
                $admitted = $completion->refused($instance, $current, $refusal->errorCode, $refusal->getMessage());
            }
            $this->operations->change($current, ['result' => [...($current->result ?? []), 'completion_operation_id' => $admitted->id]]);
        });
    }
}
