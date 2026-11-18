<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\NestedFleetIntent;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

use function Illuminate\Support\defer;

/** `instance:wake` and the wake of a Route visit: one open wake per Instance, which a second caller joins. */
final readonly class InstanceWake extends InstancePower
{
    public const string Kind = 'instance.wake';

    /** The Operation state key of a wake that tries the Node's resume first (ContainerResume): `node`, then `workflow` or `resumed`. */
    public const string ResumeState = 'wake_resume';

    /** When the wake's run starts anyway if the Gateway never finished the Node's resume (ContainerResume's worst case is about 5 s). */
    public const int SafetyNetSeconds = 15;

    /** The closed reason codes of a wake's `skipped` or `timed_out` restore (docs/reference/prepared-restore.md#wake). */
    public const array PreparedReasons = ['no_plan', 'plan_invalid', 'plan_stale', 'unsupported', 'placement_unproven', 'deadline', 'nothing_read'];

    /** The run found a nested-fleet container stopped: it boots none, since only a start or a deployment brings back its fleet. */
    public const string NestedStopped = 'instance.nested_fleet_stopped';

    protected function kind(): string
    {
        return self::Kind;
    }

    protected function powerState(): InstanceRuntimePowerState
    {
        return InstanceRuntimePowerState::Running;
    }

    /** The container sleeps frozen: its runtime records it stopped and its last sleep froze it (ContainerResume::frozenBySleep). */
    private static function frozen(InstanceRuntime $runtime, Instance $instance): bool
    {
        return ExecutorPowerIntent::frozenSleep($instance, $runtime) !== null;
    }

    /** A nested-fleet default that no sleep froze starts only through its deployment, which brings back its Club key and fleet. */
    protected function refuse(Instance $instance, InstanceRuntime $runtime): void
    {
        if ($runtime->incus_project === InstancePlacement::NestedProject && ! self::frozen($runtime, $instance)) {
            throw new ResourceOperationException('instance.sleep_unsupported',
                'A nested-fleet Instance that no sleep froze starts only through its deployment, or a clone through its start.', 409);
        }
    }

    /**
     * A frozen nested-fleet Instance wakes by resume: its fleet, `wg-nested` and the Club key in /run survive a freeze. A nested-fleet
     * clone that no sleep froze (a host reboot, an OOM kill, a stop by hand) wakes by its start: the container, its Club key and its
     * inner fleet (InstanceStart, docs/reference/development-containers.md#a-stopped-nested-fleet-clone-starts). The request's
     * Idempotency-Key then keys that start. Every other Instance takes the wake below.
     */
    public function start(Instance $instance, ?string $caller, ?string $requestId): Operation
    {
        $operation = $this->admitWake($instance, $caller, $requestId);
        if (ExecutorWake::owns($operation)) {
            if (ExecutorPowerIntent::managed($operation)) {
                if ($operation->wasRecentlyCreated) {
                    app(ExecutorWake::class)->dispatchDeferred($operation->id);
                }

                return $operation;
            }
            app(ExecutorWake::class)->request($operation);

            return $operation->refresh();
        }

        return $this->resume($operation);
    }

    /** Record or join the same wake for a durable caller that does not wait for Node work. */
    public function admitDeferred(Instance $instance, ?string $caller, ?string $requestId): Operation
    {
        $operation = $this->admitWake($instance, $caller, $requestId);
        if ($operation->wasRecentlyCreated && ExecutorWake::owns($operation)) {
            app(ExecutorWake::class)->dispatchDeferred($operation->id);
        }

        return $operation;
    }

    private function admitWake(Instance $instance, ?string $caller, ?string $requestId): Operation
    {
        $runtime = $instance->runtime()->first();
        if (! NestedFleetIntent::cloneKeepsFleet($instance, $runtime) || ($runtime !== null && self::frozen($runtime, $instance))) {
            return parent::start($instance, $caller, $requestId);
        }
        $idempotency = app(OperationIdempotency::class);
        if ($idempotency->key === null || $idempotency->fingerprint === null) {
            return app(InstanceStart::class)->start($instance, $caller, $requestId);
        }

        return $idempotency->under(InstanceStart::Kind, $idempotency->key, $idempotency->fingerprint,
            fn (): Operation => app(InstanceStart::class)->start($instance, $caller, $requestId), parent: $idempotency->parent(self::Kind));
    }

    /**
     * FrankenPHP's port in the container, how long the wake waits for it to answer, and how long a 5xx answer may last before the
     * wake serves the app as it is: the marker goes once the app answers. A resumed container gets `resume_seconds` for its first
     * answer; a silent one is stopped and booted, which a visitor prefers to a wait without end.
     */
    protected function intent(Instance $instance): array
    {
        return ['web' => ['port' => InstanceContainer::WebPort, 'ready_seconds' => 30, 'settle_seconds' => 5, 'resume_seconds' => 10]];
    }

    /** The wake's path (`resumed`, `started`, `restarted_after_resume`, `already_running`), the marker it found and a resume's seconds. */
    protected function phaseKeys(): array
    {
        return ['wake', 'marker_found', 'resume_seconds', 'web_probe_seconds'];
    }

    /**
     * The run's prepared restore facts with only their documented fields, each of its type (docs/reference/prepared-restore.md#results):
     * the restore's status and reason, `restore_mode` from that status, and `ready_at` with its resolution. A value outside the contract
     * is left out, never guessed; a number the run did not return stays absent.
     */
    #[\Override]
    protected function kept(array $phase): array
    {
        $kept = parent::kept($phase);
        $prepared = $phase['prepared_restore'] ?? null;
        $status = is_array($prepared) ? ($prepared['status'] ?? null) : null;
        if (in_array($status, [...array_values(ContainerResume::ReadyStatuses), 'not_applicable'], true)) {
            $reason = $prepared['reason'] ?? null;
            $kept['prepared_restore'] = ['status' => $status, ...(in_array($reason, self::PreparedReasons, true) ? ['reason' => $reason] : [])];
            $kept['restore_mode'] = in_array($status, ContainerResume::PreparedStatuses, true) ? 'prepared' : 'ordinary';
        }
        $readyAt = $phase['ready_at'] ?? null;
        $resolution = $phase['ready_at_resolution_seconds'] ?? null;
        if (is_string($readyAt) && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d{3})?Z$/', $readyAt) === 1 && in_array($resolution, [1, 0.001], true)) {
            $kept['ready_at'] = $readyAt;
            $kept['ready_at_resolution_seconds'] = $resolution;
        }

        return $kept;
    }

    /**
     * A woken Instance's extensions catch up, such as Conn's renewal of a session that ended or ends soon while it slept. A wake that
     * tried the Node's fast wake then removes its request and outcome files, after the projection and off the visitor's path.
     */
    protected function ended(Operation $operation): void
    {
        $instance = Instance::query()->with('node')->find($operation->target_id);
        if ($operation->status === OperationStatus::Succeeded && $instance !== null) {
            app(InstanceLifecycleContributions::class)->projectWake($instance);
        }
        if (isset($operation->state[self::ResumeState]) && $instance?->node !== null) {
            $node = $instance->node;
            defer(static fn () => app(ContainerResume::class)->cleanUp($operation, $node), always: true);
        }
    }

    /**
     * A wake whose T3 stayed silent fails after the marker went: the container runs and its site serves the app, so the runtime
     * records `running` (docs/reference/development-containers.md#sleep-and-wake).
     */
    protected function reached(Operation $operation): bool
    {
        return parent::reached($operation) || $operation->error_code === 'instance.t3_unready';
    }

    /**
     * A frozen container's wake is recorded without its run: the Node's resume comes first, after the commit
     * (resumeOnNode(): within the command's call, or after a visit's answer).
     *
     * @param  array<string, mixed>  $input
     */
    protected function admit(Instance $instance, InstanceRuntime $runtime, array $input, ?string $caller, ?string $requestId): Operation
    {
        if (ExecutorPowerIntent::forInstance($instance, $runtime)) {
            return app(ExecutorWake::class)->admit($instance, [...$input, ExecutorPowerIntent::Managed => true], $caller, $requestId);
        }
        if (ExecutorWake::enabled() && ExecutorPowerIntent::frozenSleep($instance, $runtime) === null) {
            return app(ExecutorWake::class)->admit($instance, $input, $caller, $requestId);
        }

        if (! app(ContainerResume::class)->applies($instance, $runtime, $input)) {
            return parent::admit($instance, $runtime, $input, $caller, $requestId);
        }
        $operation = app(StartOperationAction::class)->execute(self::Kind, 'instance', $instance->id, $instance->node_id, $input,
            $caller, $requestId, lockKey: OperationStore::lockKey('instance', $instance->id), start: false);
        if ($operation->wasRecentlyCreated) {
            $operation->forceFill(['state' => [...($operation->state ?? []), self::ResumeState => 'node']])->save();
            // A safety net for a Gateway worker that dies during the Node's resume: the run starts after the bound has long
            // passed. The job starts nothing once the Operation has ended or its run has started.
            $id = $operation->id;
            DB::afterCommit(static fn () => Bus::dispatch((new StartAdmittedSwampRun($id))->delay(now()->addSeconds(self::SafetyNetSeconds))));
        }

        return $operation;
    }

    /** Whether the Operation is a wake admitted for the Node's resume that has not ended or left for its workflow. */
    private static function awaitsNode(Operation $operation): bool
    {
        return $operation->status === OperationStatus::Queued && ($operation->state[self::ResumeState] ?? null) === 'node';
    }

    /**
     * The command's wake: the Node's resume of a wake admitted for it runs within the call. A caller who joins such a wake waits
     * for it up to the bound, so it gets the ended Operation too.
     */
    private function resume(Operation $operation): Operation
    {
        if (! self::awaitsNode($operation)) {
            return $operation;
        }
        if (! $operation->wasRecentlyCreated) {
            $until = ContainerResume::now() + ContainerResume::BoundSeconds + 0.5;
            while (ContainerResume::now() < $until && $operation->status === OperationStatus::Queued
                && ($operation->state[self::ResumeState] ?? null) === 'node') {
                Sleep::usleep(100_000);
                $operation->refresh();
            }

            return $operation;
        }

        return $this->resumeOnNode($operation);
    }

    /**
     * The Node's resume of a wake admitted for it, after the admission committed: on success the Operation ends here; otherwise
     * the same Operation starts its workflow.
     */
    private function resumeOnNode(Operation $operation): Operation
    {
        $startedAt = Carbon::now();
        try {
            $instance = Instance::query()->with('node')->findOrFail($operation->target_id);
            $result = app(ContainerResume::class)->run($operation, $instance->node);
            if ($result !== null && $this->resumed($operation, $result, $startedAt)) {
                $this->ended($operation);

                return $operation->refresh();
            }
        } catch (Throwable $exception) {
            Log::warning('The wake\'s Node resume failed; its workflow runs', ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
        }
        $operation->refresh();
        if ($operation->status === OperationStatus::Queued) {
            app(OperationStore::class)->change($operation, ['state' => [...($operation->state ?? []), self::ResumeState => 'workflow']]);
            app(SwampDispatch::class)->start($operation);
        }

        return $operation->refresh();
    }

    /**
     * Records the Node's resume on the Operation only while it is still the queued wake that waits for it: a cancel in the
     * meantime keeps its own end.
     *
     * @param  array<string, mixed>  $result
     */
    private function resumed(Operation $operation, array $result, Carbon $startedAt): bool
    {
        return DB::transaction(function () use ($operation, $result, $startedAt): bool {
            $current = Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== OperationStatus::Queued || ($current->state[self::ResumeState] ?? null) !== 'node') {
                return false;
            }
            app(OperationStore::class)->change($operation, ['status' => OperationStatus::Succeeded, 'result' => [...$this->result($operation), ...$result],
                'started_at' => $startedAt, 'finished_at' => Carbon::now(), 'lock_key' => null,
                'state' => [...($current->state ?? []), self::ResumeState => 'resumed']]);
            InstanceRuntime::query()->where('instance_id', $operation->target_id)->update(['power_state' => $this->powerState()]);

            return true;
        });
    }

    protected function open(Instance $instance, bool $join): ?Operation
    {
        return self::openWake($instance, self::Kind, $join);
    }

    /** Find the open wake or nested start. A join runs in the Instance admission transaction and locks the Operation against cancellation. */
    public static function openWake(Instance $instance, string $kind, bool $join): ?Operation
    {
        $query = Operation::query()->where('kind', $kind)->where('target_type', 'instance')->where('target_id', $instance->id)
            ->whereNotIn('status', OperationStatus::terminalValues())->orderBy('id');
        $operation = $join ? $query->lockForUpdate()->first() : $query->first();
        if ($join && $operation !== null && isset($operation->state['conn_launch_owner']) && ! isset($operation->state['wake_shared'])) {
            app(OperationStore::class)->change($operation, ['state' => [...$operation->state, 'wake_shared' => true]]);
        }

        return $operation;
    }

    /**
     * The runtime activation endpoint: true permits the original request after an in-bound executor wake, null shows the progress
     * page, and a string shows the failed wake until a retry. With the executor setting off, a new Node resume runs after the response.
     * An enabled executor wake never defers work to the request worker after the response.
     */
    public function activate(Instance $instance, bool $retry, string $caller): string|true|null
    {
        $budget = new ExecutorWakeBudget;
        $last = Operation::query()->whereIn('kind', [self::Kind, InstanceStart::Kind, InstanceSleep::Kind])->where('target_type', 'instance')
            ->where('target_id', $instance->id)->orderByDesc('id')->first();
        if (($failed = self::failure($last, $retry)) !== null) {
            return $failed;
        }
        $runtime = $instance->runtime()->first();
        try {
            // The request bound belongs to a wake of a signed freeze too, after the settings went off (R3).
            $operation = ExecutorWake::enabled() || ($runtime !== null && ExecutorPowerIntent::forInstance($instance, $runtime))
                ? $budget->write(fn (): Operation => $this->admitWake($instance, $caller, null))
                : $this->admitWake($instance, $caller, null);
            if ($operation === null) {
                return null;
            }
            if (ExecutorWake::owns($operation)) {
                if ($operation->wasRecentlyCreated && ExecutorPowerIntent::managed($operation)) {
                    defer(static fn () => app(ExecutorWake::class)->dispatchDeferred($operation->id), always: true);
                }

                return app(ExecutorWake::class)->request($operation, $budget) ? true : self::failure($operation->refresh(), false);
            }

            if ($operation->wasRecentlyCreated && self::awaitsNode($operation)) {
                // A 401 page is no success, so the callback must run always. A worker that dies here leaves the safety net's run.
                defer(function () use ($operation): void {
                    if (self::awaitsNode($operation->refresh())) {
                        $this->resumeOnNode($operation);
                    }
                }, always: true);
            }

            return self::failure($operation->refresh(), false);
        } catch (ResourceOperationException $exception) {
            // Other lifecycle work holds the Instance: the page polls again, and a later request starts the wake.
            if (! in_array($exception->errorCode, ['operation.target_busy', 'instance.lifecycle_busy'], true)) {
                return $exception->getMessage();
            }
        }

        return null;
    }

    /**
     * ADR 0266, R4: the one rule for what a visit shows of a wake that ended, or of a nested clone's start that stands in for it. A
     * failed or interrupted one shows its failure with a retry. A retry admits a new wake, except under an unknown order's fence (R2),
     * because no second wake can be admitted beside that order.
     */
    private static function failure(?Operation $operation, bool $retry): ?string
    {
        if ($operation === null || ! in_array($operation->kind, [self::Kind, InstanceStart::Kind], true)
            || ! in_array($operation->status, [OperationStatus::Failed, OperationStatus::Interrupted], true)
            || ($retry && ! ($operation->status === OperationStatus::Interrupted && $operation->lock_key !== null))) {
            return null;
        }

        return 'The wake failed ('.($operation->error_code ?? 'instance.wake_failed').'). Try again.';
    }
}
