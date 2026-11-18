<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Actions\Instances\StartDevelopmentInstanceCreateAction;
use App\Actions\Instances\StartInstanceCompleteAction;
use App\Actions\Instances\StartInstanceDestroyAction;
use App\Data\Instances\CreateInstanceData;
use App\Domain\Instances\InstanceBranchSelection;
use App\Domain\Instances\InstanceDestroyKind;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Power\ContainerIdleSweep;
use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Instances\Power\InstanceWake;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRemovalMember;
use App\Models\Operation;
use App\Support\ValidatedData;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/** The launch's keyed admissions through the existing Instance lifecycle. */
final readonly class ConnLaunchInstances
{
    public function __construct(
        private ConnLaunchState $state,
        private OperationIdempotency $idempotency,
        private StartDevelopmentInstanceCreateAction $create,
        private StartInstanceCompleteAction $complete,
        private StartInstanceDestroyAction $destroy,
        private InstanceWake $wake,
        private InstanceSleep $sleep,
        private ConnInstanceWorkState $work,
        private ConnAgentActivity $activity,
        private ContainerIdleSweep $idle,
    ) {}

    public function create(Operation $launch, stdClass $request): Operation
    {
        $name = ValidatedData::string($launch->input['target_name']);
        $project = ValidatedData::integer($launch->input['project_id']);
        $node = ValidatedData::integer($launch->node_id);
        $branch = $request->target->branch ?? null;
        $selection = $branch instanceof stdClass
            ? new InstanceBranchSelection($branch->mode, $branch->mode === 'new' ? $name : $branch->name,
                $branch->mode === 'new' ? $branch->from : null)
            : null;
        $key = self::key($launch, 'create', 'launch');
        $data = new CreateInstanceData($project, $node, $name, null, null, $selection?->target, branchSelection: $selection);

        return $this->keyed($launch, 'instance.create', $key, fn (): Operation => $this->create->execute($data, $launch->triggered_by, $launch->request_id,
            connLaunchId: $launch->id, within: fn (Closure $record): Operation => $this->record($launch, ConnLaunchChild::Create, $key, $record)));
    }

    public function wake(Operation $launch, Instance $instance): ?Operation
    {
        $key = self::key($launch, 'wake', 'launch');

        return $this->state->admitChild($launch->id, ConnLaunchChild::Wake, $key, function (Operation $parent) use ($instance, $key): array {
            $operation = $this->keyed($parent, InstanceWake::Kind, $key,
                fn (): Operation => $this->wake->admitDeferred($instance, $parent->triggered_by, $parent->request_id));

            return ['operation' => $operation, 'owned' => $operation->idempotency_key === $key && $operation->triggered_by === $parent->triggered_by];
        });
    }

    public function wakeRestoration(Instance $instance, ?Operation $launch = null): ?Operation
    {
        return Operation::query()->where('kind', InstanceSleep::Kind)->where('target_type', 'instance')
            ->where('target_id', $instance->id)->where('node_id', $instance->node_id)
            ->when($launch, static fn ($query, Operation $owner) => $query->where('triggered_by', $owner->triggered_by))
            ->where('idempotency_key', 'like', 'conn-launch:'.($launch->id ?? '%').':restore-wake:%')->latest('id')->first();
    }

    public function canRestoreWake(Operation $launch, Instance $instance, Operation $wake): ?bool
    {
        return $this->usedByAnotherLaunch($launch, $instance, $wake->started_at)
            ? false : $this->idle->canRestoreWake($instance, $wake);
    }

    public function restoreWake(Operation $launch, Instance $instance, Operation $wake, ?Operation $previous): ?Operation
    {
        $key = self::key($launch, 'restore-wake', predecessor: $previous?->id);

        return $this->keyed($launch, InstanceSleep::Kind, $key, fn (): ?Operation => $this->sleep->startIf(
            $instance, $launch->triggered_by, $launch->request_id,
            fn (Instance $target): bool => $this->ownsRestorableWake($launch->id, $target, $wake->id, $previous?->id),
        ));
    }

    /** The Instance lock orders this check with other launch claims. */
    private function ownsRestorableWake(int $launchId, Instance $instance, int $wakeId, ?int $previousSleepId): bool
    {
        $launch = Operation::query()->findOrFail($launchId);
        $record = Arr::get($launch->state ?? [], 'launch_children.'.ConnLaunchChild::Wake->value);
        if ($launch->cancel_requested_at === null || $launch->status->terminal() || ConnLaunchChild::instanceId($launch) !== $instance->id
            || $launch->node_id !== $instance->node_id || ($launch->input['project_id'] ?? null) !== $instance->project_id
            || ! is_array($record) || ($record['id'] ?? null) !== $wakeId || ($record['owned'] ?? null) !== true
            || ! is_string($record['key'] ?? null)) {
            return false;
        }

        $wake = ConnLaunchChild::Wake->operation($launch, lock: true);
        $runtime = $instance->runtime()->first();

        return $wake !== null && $runtime !== null
            && $wake->triggered_by === $launch->triggered_by && $wake->idempotency_key === $record['key']
            && ($wake->state['conn_launch_owner'] ?? null) === $launchId && ($wake->state['wake_shared'] ?? false) !== true
            && $this->wakeRestoration($instance, $launch)?->id === $previousSleepId
            && ! $this->usedByAnotherLaunch($launch, $instance, $wake->started_at)
            && $this->idle->wakeStillRestorable($instance, $runtime, $wake);
    }

    public function observeCompletion(Operation $launch): ?Operation
    {
        $recorded = ConnLaunchChild::Completion->operation($launch);
        $completion = $this->laterCompletion($launch, $recorded->id ?? 0);

        return $completion === null ? $recorded : $this->state->observeCompletion($launch->id, $completion);
    }

    private function laterCompletion(Operation $launch, int $after): ?Operation
    {
        return ConnLaunchChild::completions()->where('target_id', ConnLaunchChild::instanceId($launch))
            ->where('node_id', $launch->node_id)->where('id', '>', $after)->latest('id')->first();
    }

    public function complete(Operation $launch, Instance $instance): Operation
    {
        $key = self::key($launch, 'complete');

        return $this->keyed($launch, StartInstanceCompleteAction::Kind, $key, fn (): Operation => $this->record($launch, ConnLaunchChild::Completion, $key,
            fn (): Operation => $this->admitCloneCompletion($launch, $instance, retry: false)));
    }

    public function retryApplication(Operation $launch, string $retryId): void
    {
        $key = self::key($launch, 'complete', 'application');
        $this->keyed($launch, StartInstanceCompleteAction::Kind, $key, fn (): ?Operation => $this->state->admitApplicationRetry(
            $launch->id, $retryId, $key, function (Operation $parent): array {
                $instance = Instance::query()->where('node_id', $parent->node_id)->where('project_id', $parent->input['project_id'])
                    ->lockForUpdate()->find(ConnLaunchChild::instanceId($parent));
                if ($instance === null) {
                    StartInstanceCompleteAction::refuse('The launch target Instance is unavailable.');
                }

                $completion = $this->laterCompletion($parent, ConnLaunchChild::Completion->operationId($parent) ?? 0);
                if ($completion !== null) {
                    return ['operation' => $completion, 'owned' => false];
                }

                $operation = ($parent->input['target_kind'] ?? null) === 'new'
                    ? $this->admitCloneCompletion($parent, $instance, retry: true)
                    : $this->complete->execute($instance, $parent->triggered_by, $parent->request_id);

                return ['operation' => $operation, 'owned' => true];
            }));
    }

    private function admitCloneCompletion(Operation $launch, Instance $instance, bool $retry): Operation
    {
        try {
            return DB::transaction(fn (): Operation => $retry
                ? $this->complete->execute($instance, $launch->triggered_by, $launch->request_id)
                : $this->complete->admit($instance, $launch->triggered_by, $launch->request_id));
        } catch (ResourceOperationException $error) {
            $create = ConnLaunchChild::Create->operation($launch);
            if ($create === null) {
                throw $error;
            }

            return $this->complete->refused($instance, $create, $error->errorCode, 'The application completion could not be admitted.', keyed: true);
        }
    }

    public function destroy(Operation $launch, Instance $instance): Operation
    {
        $create = ConnLaunchChild::Create->operation($launch);
        $previous = $this->ownedDestroy($launch);
        $unstarted = $create !== null && self::unstartedReservation($instance, $create);
        $stoppedRemoval = $unstarted ? null : $this->assertCleanupSafe($launch, $instance, $previous);
        $key = self::key($launch, 'destroy', predecessor: $previous?->id);

        return $this->keyed($launch, InstanceDestroyKind::forInstance($instance), $key,
            fn (): Operation => $this->destroy->execute($instance, true, $launch->triggered_by, $launch->request_id,
                within: fn (Closure $record): Operation => $this->state->admitChild($launch->id, ConnLaunchChild::Destroy, $key,
                    function (Operation $parent) use ($instance, $create, $previous, $unstarted, $stoppedRemoval, $record): array {
                        $target = Instance::query()->lockForUpdate()->findOrFail($instance->id);
                        $owned = ConnLaunchChild::Create->operation($parent);
                        if ($owned === null || $owned->id !== $create?->id || ($owned->state['conn_launch_owner'] ?? null) !== $parent->id
                            || $target->source_prepare_id === null || $target->source_prepare_id !== ($owned->input['prepare_id'] ?? null)
                            || ConnLaunchChild::Destroy->operationId($parent) !== $previous?->id
                            || ($target->status === InstanceState::Removing && $previous === null)
                            || ($stoppedRemoval !== null && ! self::retainsRemoval($target, $stoppedRemoval))
                            || ($unstarted && ! self::unstartedReservation($target, $owned))) {
                            throw new ResourceOperationException('instance.unsaved_work', 'The launch cannot prove it still owns this clone.', 409);
                        }
                        if ($this->usedByAnotherLaunch($parent, $target)) {
                            throw new ResourceOperationException('instance.unsaved_work', 'Another Conn launch uses this Instance.', 409);
                        }

                        return ['operation' => $record(), 'owned' => true];
                    }) ?? throw new ResourceOperationException('conn.launch_fence_closed', 'The launch cannot admit more work.', 409)));
    }

    private static function unstartedReservation(Instance $instance, Operation $create): bool
    {
        return $instance->status === InstanceState::Reserved && $create->status !== OperationStatus::Succeeded
            && $create->attempts === 0 && ! isset($create->state['swamp_run_id'])
            && ! Operation::query()->where('kind', 'instance.create')->where('target_type', 'instance')
                ->where('target_id', $instance->id)->whereKeyNot($create->id)->exists();
    }

    private function usedByAnotherLaunch(Operation $launch, Instance $instance, ?DateTimeInterface $since = null): bool
    {
        return Operation::query()->where('kind', OperationKinds::ConnLaunch)->whereKeyNot($launch->id)
            ->whereNot('status', OperationStatus::Cancelled)
            ->where(static fn ($query) => $query->where('input->instance_id', $instance->id)
                ->orWhere('state->launch_instance_id', $instance->id))
            ->when($since !== null, static fn ($query) => $query->where(static fn ($used) => $used->whereNull('finished_at')
                ->orWhere('finished_at', '>=', $since)))
            ->exists();
    }

    private function ownedDestroy(Operation $launch): ?Operation
    {
        $record = Arr::get($launch->state ?? [], 'launch_children.'.ConnLaunchChild::Destroy->value);
        $previous = ConnLaunchChild::Destroy->operation($launch);

        return $previous !== null && is_array($record) && ($record['owned'] ?? null) === true && is_string($record['key'] ?? null)
            && $previous->idempotency_key === $record['key'] && $previous->triggered_by === $launch->triggered_by
            && ($previous->state['conn_launch_owner'] ?? null) === $launch->id ? $previous : null;
    }

    /** Return the retained removal ID when the workspace was safe to skip because its owned native run stopped it. */
    private function assertCleanupSafe(Operation $launch, Instance $instance, ?Operation $previous): ?string
    {
        $work = $this->work->read($instance);
        $removal = $previous?->input['removal_id'] ?? null;
        if (($work['reason'] ?? null) === 'stopped' && $previous !== null && is_string($removal)
            && self::retainsRemoval($instance, $removal) && $this->hasVerifiedStop($launch, $previous, $removal)) {
            return $removal;
        }
        $activity = $work['state'] === 'clean' ? $this->activity->read($instance) : null;
        if ($activity === null || ! $activity->readable || $activity->turnRuns || $activity->lastActivityUnix !== null) {
            throw new ResourceOperationException('instance.unsaved_work', 'The Instance may hold work from another caller.', 409);
        }

        return null;
    }

    private static function retainsRemoval(Instance $instance, string $removal): bool
    {
        return $instance->status === InstanceState::Removing && InstanceRemovalMember::query()->live()
            ->where('instance_id', $instance->id)->where('instance_removal_id', $removal)->exists();
    }

    /** Walk only the recorded predecessor links, since a refused start has no Stop result of its own. */
    private function hasVerifiedStop(Operation $launch, Operation $previous, string $removal): bool
    {
        $chain = Operation::query()->where('kind', $previous->kind)->where('target_type', $previous->target_type)
            ->where('target_id', $previous->target_id)->where('node_id', $previous->node_id)->where('triggered_by', $launch->triggered_by)
            ->where('state->conn_launch_owner', $launch->id)->where('input->removal_id', $removal);
        $attempt = $previous;
        while ($attempt !== null && $attempt->status->terminal()) {
            $key = $attempt->idempotency_key ?? '';
            $id = (int) Str::afterLast($key, ':');
            $predecessor = $id > 0 ? $id : null;
            if ($key !== self::key($launch, 'destroy', predecessor: $predecessor) || $id >= $attempt->id) {
                return false;
            }
            if (($attempt->state['container_stop_succeeded'] ?? null) === true) {
                return true;
            }

            $attempt = $predecessor === null ? null : (clone $chain)->find($predecessor);
        }

        return false;
    }

    /** @param Closure(): Operation $admit */
    private function record(Operation $launch, ConnLaunchChild $role, string $key, Closure $admit): Operation
    {
        return $this->state->admitChild($launch->id, $role, $key,
            static fn (): array => ['operation' => $admit(), 'owned' => true])
            ?? throw new ResourceOperationException('conn.launch_fence_closed', 'The launch cannot admit more work.', 409);
    }

    /** @template T
     * @param  Closure(): T  $admit
     * @return T
     */
    private function keyed(Operation $launch, string $kind, string $key, Closure $admit): mixed
    {
        return $this->idempotency->under($kind, $key, hash('sha256', $kind."\0".$key), $admit, parent: $launch);
    }

    public static function key(Operation $launch, string $phase, ?string $retry = null, ?int $predecessor = null): string
    {
        $number = $retry === null ? $predecessor : Arr::get($launch->state ?? [], 'launch_last_retry.'.$retry.'.id');

        return 'conn-launch:'.$launch->id.':'.$phase.':'.(is_string($number) || is_int($number) ? $number : 'initial');
    }
}
