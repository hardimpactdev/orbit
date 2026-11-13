<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\ExpireOperationAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceState;
use App\Domain\NodeFacts\NodeAgentLiveness;
use App\Domain\NodeFacts\NodeAgentState;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Routes\NativeRouteCreatePlan;
use App\Domain\Routes\NativeRouteLifecycleInput;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Models\DatabaseConnection;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\NodeFact;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Admits `instance:restore` for a host development Instance whose removal failed with nothing deleted
 * (docs/reference/instance-removal.md#restore-a-failed-removal). It reads only recorded state; the run checks the databases.
 */
final readonly class StartInstanceRestoreAction
{
    /** Removal steps that delete data; any of them that ran makes the removal irreversible. */
    public const array DeletingSteps = ['Remove the source', 'Delete the container', 'Stop, remove Processes', 'Run teardown steps', 'Remove SQLite files'];

    public function __construct(private StartOperationAction $operations, private OperationIdempotency $idempotency, private NodeAgentLiveness $liveness) {}

    public function execute(Instance $instance, ?string $caller = null, ?string $requestId = null): Operation
    {
        if (($replay = $this->idempotency->replay('instance.restore')) !== null) {
            return $replay;
        }

        return DB::transaction(function () use ($instance, $caller, $requestId): Operation {
            $instance = Instance::query()->with(['node', 'project'])->lockForUpdate()->findOrFail($instance->id);
            if (! $instance->placedOnAppDev() || $instance->defaultAppEnv() !== 'development' || $instance->runtime()->exists()) {
                self::refuse('unsupported', 'Restore supports only a host development Instance.');
            }
            $member = InstanceRemovalMember::query()->live()->where('instance_id', $instance->id)->lockForUpdate()->first();
            $removal = $member === null ? null : InstanceRemoval::query()->lockForUpdate()->find($member->instance_removal_id);
            if ($instance->status !== InstanceState::Removing || $removal === null || $removal->status !== InstanceRemovalStatus::Failed
                || $removal->requested_instance_id !== $instance->id || ! is_array($removal->native_intent)) {
                self::refuse('state_invalid', 'The Instance must be removing, with its own failed removal.');
            }
            if ($removal->members()->count() !== 1) {
                self::refuse('unsupported', 'The removal holds other Instances.');
            }
            $this->assertRunsEnded($removal);
            $saved = $removal->native_intent;
            if (($saved['container_destroy'] ?? null) !== null) {
                self::refuse('unsupported', 'Restore supports only a host development Instance.');
            }
            $detail = ValidatedData::object($saved['direct_destroy'] ?? []);
            $runtime = ValidatedData::object($detail['runtime'] ?? []);
            $database = ValidatedData::object($detail['database'] ?? []);
            if (($runtime['schedules'] ?? []) !== []) {
                self::refuse('unsupported', 'The removal already withdrew the Instance Schedules.');
            }
            if (($database['sqlite'] ?? []) !== []) {
                self::refuse('unsupported', 'The removal planned SQLite files; restore cannot prove they remain.');
            }
            $servers = RouteExecutionProof::objects($database['servers'] ?? []);
            foreach ($servers as $server) {
                if ((ValidatedData::object($server['intent'])['driver'] ?? null) !== DatabaseDriver::Pgsql->value) {
                    self::refuse('unsupported', 'Restore checks only PostgreSQL database servers.');
                }
            }
            $savedConnections = ValidatedData::integerList($database['connection_ids'] ?? []);
            sort($savedConnections);
            $owned = DatabaseConnection::query()->where('owner_instance_id', $instance->id)->orderBy('id')->pluck('id')->all();
            if ($owned !== $savedConnections) {
                self::refuse('already_removed', 'The owned databases changed since the removal.');
            }
            $route = $this->route($instance, $member);
            $this->assertIdle($instance, $route);
            $this->assertCheckout($instance);
            $candidate = self::candidate($route);
            $capture = app(NativeRouteLifecycleInput::class)->capture($candidate, [], preserveEnvironment: true,
                preparedWorkloads: true, wakePreparedWorkloads: true);
            $lifecycle = $capture['intent'];
            // The checkout keeps its .env: the same Route writes no APP_URL.
            $mutations = ValidatedData::object($lifecycle['route_mutations']);
            foreach (['forward', 'restore'] as $side) {
                $mutations[$side] = [...ValidatedData::object($mutations[$side]), 'url_writes' => []];
            }
            $lifecycle['route_mutations'] = $mutations;
            $input = ['instance_id' => $instance->id, 'name' => $instance->name, 'removal_id' => $removal->id, 'route_id' => $route->id,
                'servers' => array_map(static fn (array $server): array => ['server_id' => ValidatedData::integer($server['server_id']),
                    'node_id' => ValidatedData::integer($server['node_id']), 'container' => ValidatedData::string(ValidatedData::object($server['intent'])['container']),
                    'databases' => ValidatedData::stringList(ValidatedData::object($server['intent'])['databases'])], $servers),
                'route_lifecycle' => $lifecycle, 'route_create' => app(NativeRouteCreatePlan::class)->forCreate($candidate, $lifecycle),
                'participant_node_ids' => array_values(array_unique([$instance->node_id, ...ValidatedData::integerList($capture['participant_node_ids'])])),
                'route_secrets' => $capture['execution_secrets'], 'run_budget_seconds' => 900];

            return $this->operations->execute('instance.restore', 'instance', $instance->id, $instance->node_id, $input, $caller, $requestId,
                lockKey: OperationStore::lockKey('instance', $instance->id));
        });
    }

    /**
     * The Route as restore publishes it: active, with its removing targets active in memory only. The stored records stay
     * removing and retiring until the run succeeds. Admission and a promoted waiting restore render from it.
     */
    public static function candidate(Route $route): Route
    {
        $route->loadMissing(['targets.instance.node', 'targets.instance.project']);
        $candidate = clone $route;
        $candidate->setRelation('targets', $route->targets->map(static function ($target) {
            $copy = clone $target;
            $instance = clone $target->instance;
            $instance->status = InstanceState::Active;

            return $copy->setRelation('instance', $instance);
        }));

        return $candidate;
    }

    /** Every run of the removal has ended, and none of them ran a step that deletes data. */
    private function assertRunsEnded(InstanceRemoval $removal): void
    {
        $runs = Operation::query()->where('kind', 'instance.destroy')->where('input->removal_id', $removal->id)->orderBy('id')->get();
        foreach ($runs as $run) {
            // A timed-out run without a proven native end may still be writing; restore never settles it.
            if (! $run->status->terminal() || ExpireOperationAction::expiredUnsettled($run) || ExpireOperationAction::awaitsSettlement($run)) {
                self::refuse('state_invalid', "Removal Operation {$run->id} has not ended.");
            }
            $progress = $run->state['native_progress'] ?? [];
            $steps = is_array($progress) && is_array($progress['steps'] ?? null) ? array_values($progress['steps']) : [];
            foreach ($steps as $step) {
                $name = is_array($step) && is_string($step['name'] ?? null) ? $step['name'] : '';
                foreach (self::DeletingSteps as $label) {
                    if (($name === $label || str_starts_with($name, $label.'-')) && ($step['status'] ?? null) !== 'skipped') {
                        self::refuse('already_removed', "The removal ran {$label}.");
                    }
                }
            }
        }
    }

    /** The one Route the removal withdrew, still retiring with only this Instance as its target. */
    private function route(Instance $instance, InstanceRemovalMember $member): Route
    {
        $ids = $member->route_ids ?? ($member->route_id === null ? [] : [$member->route_id]);
        $route = count($ids) === 1 ? Route::query()->with(['targets.instance.node', 'targets.instance.project'])->lockForUpdate()->find($ids[0]) : null;
        if ($route === null || $route->status !== RouteStatus::Retiring || $route->targets->pluck('instance_id')->all() !== [$instance->id]
            || Route::query()->whereHas('targets', fn ($query) => $query->where('instance_id', $instance->id))->count() !== 1) {
            self::refuse('already_removed', 'The removal must have withdrawn exactly one Route, still retiring with this Instance as its target.');
        }

        return $route;
    }

    private function assertIdle(Instance $instance, Route $route): void
    {
        $open = Operation::query()->whereNotIn('status', OperationStatus::terminalValues())
            ->where(fn ($query) => $query->where(fn ($q) => $q->where('target_type', 'instance')->where('target_id', $instance->id))
                ->orWhere(fn ($q) => $q->where('target_type', 'route')->where('target_id', $route->id)))->exists();
        if ($open || Operation::query()->where('lock_key', OperationStore::lockKey('instance', $instance->id))->exists()) {
            self::refuse('busy', 'Another Operation holds the Instance or its Route.');
        }
    }

    /** The Node agent's latest report of the checkout, trusted only while the agent is online, as `instance:rename` does. */
    private function assertCheckout(Instance $instance): void
    {
        $fact = NodeFact::query()->where('node_id', $instance->node_id)->where('kind', NodeFactKind::Checkout->value)
            ->where('subject', $instance->checkout_path)->first();
        if ($this->liveness->for($instance->node)?->state !== NodeAgentState::Online || ! $fact instanceof NodeFact) {
            self::refuse('source_changed', 'The Node agent has not reported the checkout recently.');
        }
        $origin = $fact->data['origin'] ?? null;
        try {
            $identity = is_string($origin) && ! str_contains($origin, 'REDACTED@') ? GitRepositoryIdentity::derive($origin) : null;
        } catch (\InvalidArgumentException) {
            $identity = null;
        }
        if (($fact->data['top_level'] ?? null) !== $instance->checkout_path || $identity !== $instance->project->repository_identity) {
            self::refuse('source_changed', 'The checkout no longer matches its recorded path and repository.');
        }
    }

    public static function refuse(string $reason, string $message): never
    {
        throw new ResourceOperationException('instance.restore_'.$reason, $message, 409);
    }
}
