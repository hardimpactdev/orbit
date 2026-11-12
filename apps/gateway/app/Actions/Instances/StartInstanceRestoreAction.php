<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\DatabaseServers\InstanceRestoreFence;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\RemotePrograms\RemoteProgramFailed;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Routes\NativeRouteCreatePlan;
use App\Domain\Routes\NativeRouteLifecycleInput;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Infrastructure\Swamp\ReadNativeRunFailure;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Route;
use App\Models\Schedule;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Abandon only a failed removal whose full admitted inventory survives. No recovery effects run here. */
final readonly class StartInstanceRestoreAction
{
    public function __construct(private StartOperationAction $operations, private OperationIdempotency $idempotency,
        private RemoteProgramRunner $reader) {}

    public function execute(Instance $instance, ?string $caller = null, ?string $requestId = null): Operation
    {
        if (($replay = $this->idempotency->replay('instance.restore')) !== null) {
            return $replay;
        }

        return DB::transaction(function () use ($instance, $caller, $requestId): Operation {
            $instance = Instance::query()->lockForUpdate()->findOrFail($instance->id);
            // SQLite's admission mutex must be held across external evidence and publication capture too.
            DB::table('instances')->where('id', $instance->id)->update(['updated_at' => $instance->getRawOriginal('updated_at')]);
            if ($instance->status === InstanceState::Active) {
                return $this->alreadyRestored($instance, $caller, $requestId);
            }
            $member = InstanceRemovalMember::query()->live()->where('instance_id', $instance->id)->lockForUpdate()->first();
            if ($member === null) {
                if ($instance->status === InstanceState::Removing && InstanceRemoval::query()
                    ->where('requested_instance_id', $instance->id)->where('status', InstanceRemovalStatus::Failed)->exists()) {
                    self::refuse('member_missing', 'The failed removal has lost its planned Instance member.');
                }
                self::refuse('not_failed', 'The Instance has no live failed removal.');
            }
            $removal = InstanceRemoval::query()->lockForUpdate()->findOrFail($member->instance_removal_id);
            if (! $instance->placedOnAppDev() || $instance->status !== InstanceState::Removing
                || $removal->status !== InstanceRemovalStatus::Failed || $removal->requested_instance_id !== $instance->id) {
                self::refuse('not_failed', 'The Instance must be removing with its own failed live removal.');
            }
            $saved = $removal->native_intent;
            if (! is_array($saved)) {
                self::refuse('evidence_missing', 'The saved removal inventory is missing.');
            }
            if ($removal->members()->count() !== $removal->total) {
                self::refuse('member_missing', 'A planned removal member is gone.');
            }
            $members = $removal->members()->lockForUpdate()->get();
            $ids = ValidatedData::integerList($members->pluck('instance_id')->all());
            $instances = Instance::query()->whereKey($ids)->lockForUpdate()->get()->keyBy('id');
            if ($instances->count() !== count($ids)) {
                self::refuse('member_missing', 'A planned Instance member is gone.');
            }
            foreach ($members as $record) {
                $current = $instances->get($record->instance_id);
                if (! $current instanceof Instance) {
                    self::refuse('member_missing', 'A planned Instance member is gone.');
                }
                if ($record->row_deleted_at !== null) {
                    self::refuse('member_missing', 'A planned Instance member was deleted.');
                }
                if ($record->source_finalized_at !== null) {
                    self::refuse('source_removed', 'Removal completed source deletion.');
                }
                if ($current->status !== InstanceState::Removing || $record->abandoned_at !== null
                    || $current->node_id !== $record->node_id || $current->project_id !== $record->project_id
                    || $current->checkout_path !== $record->checkout_path || $current->branch !== $record->branch
                    || $current->project->repository_identity !== $record->repository_identity) {
                    self::refuse('source_changed', 'The saved Instance placement or checkout identity changed.');
                }
            }
            $detail = ValidatedData::object($saved['direct_destroy'] ?? []);
            $runtime = ValidatedData::object($detail['runtime'] ?? []);
            $processIds = ValidatedData::integerList(array_column(RouteExecutionProof::objects($runtime['processes'] ?? []), 'process_id'));
            $scheduleIds = ValidatedData::integerList(array_column(RouteExecutionProof::objects($runtime['schedules'] ?? []), 'id'));
            if ($scheduleIds !== []) {
                self::refuse('schedules_removed', 'Removal already withdrew the planned Schedule triggers.');
            }
            if (Process::query()->whereKey($processIds)->count() !== count($processIds)) {
                self::refuse('processes_removed', 'A planned Process record is gone.');
            }
            $relatedProcesses = Process::query()->whereIn('owner_type', Instance::morphTypes())->whereIn('owner_id', $ids)->pluck('id')->all();
            $relatedSchedules = Schedule::query()->whereIn('target_type', Instance::morphTypes())->whereIn('target_id', $ids)->pluck('id')->all();
            $this->idle($ids, ValidatedData::integerList($relatedProcesses), ValidatedData::integerList($relatedSchedules));
            $this->stopped($removal, $processIds, $saved);
            $database = ValidatedData::object($detail['database'] ?? []);
            $connectionIds = ValidatedData::integerList($database['connection_ids'] ?? []);
            $connections = DatabaseConnection::query()->whereKey($connectionIds)->lockForUpdate()->get();
            if ($connections->count() !== count($connectionIds)) {
                self::refuse('database_missing', 'A database record from the planned set is gone.');
            }
            foreach ($connections as $connection) {
                if (! in_array($connection->owner_instance_id, $ids, true)) {
                    self::refuse('ownership_changed', 'A planned database has a different Instance owner.');
                }
            }
            DatabaseServer::query()->whereKey(array_column(RouteExecutionProof::objects($database['servers'] ?? []), 'server_id'))->lockForUpdate()->get();
            $routeIds = $members->flatMap(fn (InstanceRemovalMember $row): array => $row->route_ids ?? ($row->route_id === null ? [] : [$row->route_id]))->unique()->sort()->values()->all();
            $routes = Route::query()->with('targets.instance.node', 'targets.instance.project')->whereKey($routeIds)->orderBy('id')->lockForUpdate()->get();
            if ($routes->count() !== count($routeIds) || $routes->count() !== 1) {
                self::refuse('route_missing', 'The one captured Route required for restoration is gone.');
            }
            $route = $routes->sole();
            $nativeDestroy = ValidatedData::object($saved['native_destroy'] ?? []);
            $routeMutations = ValidatedData::object($nativeDestroy['route_mutations'] ?? []);
            $priorPublication = ValidatedData::object($routeMutations['restore'] ?? []);
            $priorRoutes = RouteExecutionProof::objects($priorPublication['routes'] ?? []);
            $prior = array_find($priorRoutes, static fn (array $row): bool => $row['route_id'] === $route->id);
            if ($prior === null || $prior['domain'] !== $route->domain || $route->status !== RouteStatus::Retiring || $route->sites_published) {
                self::refuse('route_missing', 'The captured retiring Route or domain changed.');
            }
            $targets = $route->targets->pluck('instance_id')->sort()->values()->all();
            $expected = $members->filter(fn (InstanceRemovalMember $row): bool => in_array($route->id, $row->route_ids ?? [$row->route_id], true))->pluck('instance_id')->sort()->values()->all();
            if ($targets !== $expected) {
                self::refuse('target_missing', 'A captured Route target is gone or changed.');
            }
            $nodes = ValidatedData::integerList($detail['publication_node_ids'] ?? []);
            $ownHolds = Operation::query()->where('kind', 'instance.restore')->where('target_id', $instance->id)
                ->where('input->removal_id', $removal->id)->whereIn('status', OperationStatus::terminalValues())->pluck('id');
            if (DB::table('route_publication_holds')->whereIn('node_id', $nodes)->whereNotIn('operation_id', $ownHolds)->exists()) {
                self::refuse('busy', 'Another publication holds a Node needed by this Route.');
            }
            $program = new RemoteProgramCatalog(base_path('../../swamp/ansible'))->program('instance.restore-source');
            try {
                $source = $this->reader->run($instance->node, $program, ['action' => 'source',
                    'members' => $members->map(fn (InstanceRemovalMember $row): array => [
                        'instance_id' => $row->instance_id, 'path' => $row->checkout_path, 'root' => $row->root,
                        'repository_identity' => $row->repository_identity, 'branch' => $row->branch,
                        'commit' => $row->source_commit ?: $row->starting_commit,
                        'awake_path' => RuntimeHibernation::awakePath(RuntimeHibernation::key($row->instance_id)),
                    ])->all(), 'container' => $saved['container_destroy'] ?? null]);
            } catch (RemoteProgramFailed) {
                self::refuse('evidence_missing', 'The bounded source survivor read could not finish.');
            }
            if ($source->outcome !== 'intact') {
                $reason = ValidatedData::string($source->data['reason'] ?? 'source_changed');
                $message = match ($reason) {
                    'container_removed' => 'The planned Instance container is gone.',
                    'source_removed' => 'The planned checkout is gone.',
                    'source_changed' => 'The checkout, required workspace, branch, commit or Git origin no longer matches.',
                    default => 'The bounded source survivor evidence is incomplete.',
                };
                self::refuse($reason, $message);
            }
            $firstAttempt = Operation::query()->where('kind', 'instance.restore')->where('input->removal_id', $removal->id)->oldest('id')->first();
            $servers = app(InstanceRestoreFence::class)->capture($database, RouteExecutionProof::objects($firstAttempt?->input['servers'] ?? []));
            // Only in-memory candidates are active. Stored Instance/Route states stay removing/retiring.
            $candidate = clone $route;
            $candidate->setRelation('targets', $route->targets->map(function ($target) {
                $copy = clone $target;
                $active = clone $target->instance;
                $active->status = InstanceState::Active;

                return $copy->setRelation('instance', $active);
            }));
            $captured = app(NativeRouteLifecycleInput::class)->capture($candidate, [], preserveEnvironment: true, preparedWorkloads: true, wakePreparedWorkloads: true);
            $lifecycle = $captured['intent'];
            $mutations = ValidatedData::object($lifecycle['route_mutations']);
            foreach (['forward', 'restore'] as $side) {
                $publication = ValidatedData::object($mutations[$side]);
                $publication['url_writes'] = [];
                $mutations[$side] = $publication;
            }
            $lifecycle['route_mutations'] = $mutations;
            $plan = app(NativeRouteCreatePlan::class)->forCreate($candidate, $lifecycle);
            $databaseIntent = app(RecordDatabaseOperationAction::class)->prepare(
                'instance.restore', 'instance', $instance->id, $instance->node_id, ['removal_id' => $removal->id], []);
            $input = ['restore_database_intent_id' => $databaseIntent->input['intent_id'], 'name' => $instance->name, 'instance_id' => $instance->id, 'removal_id' => $removal->id, 'member_ids' => $members->modelKeys(),
                'route_id' => $route->id, 'route_targets' => $route->targets->map(fn ($target): array => ['id' => $target->id, 'instance_id' => $target->instance_id, 'position' => $target->position])->all(),
                'route_lifecycle' => $lifecycle, 'route_create' => $plan,
                'servers' => $servers, 'awake_before' => $firstAttempt?->input['awake_before'] ?? $source->data['awake_before'] ?? [],
                'participant_node_ids' => $captured['participant_node_ids'],
                'execution_secrets' => $captured['execution_secrets'], 'run_budget_seconds' => 900];

            return $this->operations->execute('instance.restore', 'instance', $instance->id, $instance->node_id,
                $input, $caller, $requestId, lockKey: OperationStore::lockKey('instance', $instance->id));
        });
    }

    /** @param list<int> $ids
     * @param  list<int>  $processes
     * @param  list<int>  $schedules
     */
    private function idle(array $ids, array $processes, array $schedules): void
    {
        if (Operation::query()->whereNotIn('status', OperationStatus::terminalValues())
            ->where(function ($query) use ($ids, $processes, $schedules): void {
                $query->where(fn ($row) => $row->where('target_type', 'instance')->whereIn('target_id', $ids))
                    ->orWhere(fn ($row) => $row->where('target_type', 'process')->whereIn('target_id', $processes))
                    ->orWhere(fn ($row) => $row->where('target_type', 'schedule')->whereIn('target_id', $schedules));
            })->exists()) {
            self::refuse('busy', 'Lifecycle, Process or Schedule work is still queued, running or waiting.');
        }
    }

    /** @param list<int> $processIds
     * @param  array<string,mixed>  $saved
     */
    private function stopped(InstanceRemoval $removal, array $processIds, array $saved): void
    {
        $detail = ValidatedData::object($saved['direct_destroy'] ?? []);
        $database = ValidatedData::object($detail['database'] ?? []);
        $teardown = RouteExecutionProof::objects($detail['teardown'] ?? []);
        $runs = Operation::query()->whereIn('kind', ['instance.destroy', 'instance.restore'])
            ->where('input->removal_id', $removal->id)->orderBy('id')->limit(65)->get();
        if ($runs->isEmpty() || $runs->count() > 64) {
            self::refuse('evidence_missing', 'The complete bounded removal run inventory is unavailable.');
        }
        foreach ($runs as $operation) {
            $run = $operation->state['swamp_run_id'] ?? null;
            if ($operation->kind === 'instance.restore' && $operation->status->terminal()
                && $run === null && (($operation->state['native_start_not_submitted'] ?? false) === true
                    || $operation->attempts === 0)) {
                continue;
            }
            // A projected native result already passed the shared authenticated terminal reader.
            // All unsuccessful restore outcomes use the same failed, repeatable state.
            if ($operation->kind === 'instance.restore' && $operation->status->terminal()
                && is_string($run) && $operation->finished_at !== null
                && ($operation->result['restored'] ?? null) === false
                && array_key_exists('compensation_complete', $operation->result ?? [])) {
                continue;
            }
            if (! $operation->status->terminal() || ! is_string($run)
                || ($terminal = app(ReadNativeRunFailure::class)->read($operation, $operation->kind === 'instance.destroy' ? 'operation-instance-destroy' : 'operation-instance-restore', $run)) === null
                || $terminal['finished_at'] === null) {
                self::refuse('run_unsettled', 'A native removal or restoration run has not conclusively stopped.');
            }
            if ($operation->kind !== 'instance.destroy') {
                continue;
            }
            $progress = ValidatedData::object($operation->state['native_progress'] ?? []);
            $steps = $progress['steps'] ?? null;
            if (! is_array($steps)) {
                self::refuse('evidence_missing', 'Removal step evidence is missing.');
            }
            foreach (['Delete the container' => 'container_removed', 'Remove the source' => 'source_removed',
                'Stop, remove Processes' => 'processes_removed', 'Run teardown steps' => 'teardown_ran', 'Remove SQLite files' => 'sqlite_removed'] as $label => $reason) {
                $matches = array_values(array_filter(RouteExecutionProof::objects($steps), static fn (array $row): bool => is_string($row['name'] ?? null)
                    && ($row['name'] === $label || str_starts_with($row['name'], $label.'-'))));
                if (array_any($matches, static fn (array $row): bool => ($row['status'] ?? null) !== 'skipped')) {
                    self::refuse($reason, "Removal already ran {$label}; publication cannot reverse it.");
                }
                $planned = match ($label) {
                    'Delete the container' => ($saved['container_destroy'] ?? null) !== null,
                    'Remove the source' => true,
                    'Stop, remove Processes' => $processIds !== [],
                    'Run teardown steps' => array_any($teardown, static fn (array $row): bool => ($row['steps'] ?? []) !== []),
                    default => ($database['sqlite'] ?? []) !== [],
                };
                if ($planned && $matches === []) {
                    self::refuse('evidence_missing', "Removal has no conclusive skipped evidence for {$label}.");
                }
            }
        }
    }

    private function alreadyRestored(Instance $instance, ?string $caller, ?string $requestId): Operation
    {
        $receipt = Operation::query()->where('kind', 'instance.restore')->where('target_type', 'instance')->where('target_id', $instance->id)
            ->where('status', 'succeeded')->where('result->restored', true)->latest('id')->first();
        $removal = $receipt === null ? null : InstanceRemoval::query()->find(ValidatedData::string($receipt->input['removal_id']));
        $route = $receipt === null ? null : Route::query()->find(ValidatedData::integer($receipt->input['route_id']));
        if ($removal?->status !== InstanceRemovalStatus::Abandoned || $route?->status !== RouteStatus::Active || ! $route->sites_published
            || $removal->members()->whereNull('abandoned_at')->exists() || $instance->removalMember()->exists()
            || $route->targets()->orderBy('position')->get(['id', 'instance_id', 'position'])->toArray() !== ($receipt->input['route_targets'] ?? [])) {
            self::refuse('not_failed', 'No successful restoration receipt matches the current Instance and Route.');
        }

        return $this->operations->record('instance.restore', 'instance', $instance->id, $instance->node_id, $receipt->input,
            ['restored' => true, 'instance_id' => $instance->id, 'removal_id' => $removal->id, 'route_id' => $route->id,
                'skipped' => 'instance.already_restored'], $caller, $requestId, OperationStore::lockKey('instance', $instance->id));
    }

    public static function refuse(string $reason, string $message): never
    {
        $group = match ($reason) {
            'not_failed', 'run_unsettled', 'source_changed' => 'state_invalid',
            'container_removed', 'member_missing', 'route_missing', 'target_missing', 'source_removed',
            'processes_removed', 'schedules_removed', 'sqlite_removed', 'teardown_ran' => 'already_removed',
            'busy' => 'busy',
            default => 'database_changed',
        };
        throw new ResourceOperationException('instance.restore_'.$group, $message, 409, details: ['reason' => $reason]);
    }
}
