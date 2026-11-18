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
use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Instances\Power\InstanceWake;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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

        return $this->keyed('instance.create', $key, fn (): Operation => $this->create->execute($data, $launch->triggered_by, $launch->request_id,
            connLaunchId: $launch->id, within: fn (Closure $record): Operation => $this->record($launch, ConnLaunchChild::Create, $key, $record)));
    }

    public function wake(Operation $launch, Instance $instance): ?Operation
    {
        $key = self::key($launch, 'wake', 'launch');

        return $this->state->admitChild($launch->id, ConnLaunchChild::Wake, $key, function (Operation $parent) use ($instance, $key): array {
            $operation = $this->keyed(InstanceWake::Kind, $key,
                fn (): Operation => $this->wake->admitDeferred($instance, $parent->triggered_by, $parent->request_id));

            return ['operation' => $operation, 'owned' => $operation->idempotency_key === $key && $operation->triggered_by === $parent->triggered_by];
        });
    }

    public function wakeRestoration(Operation $launch, Instance $instance): ?Operation
    {
        return Operation::query()->where('kind', InstanceSleep::Kind)->where('target_type', 'instance')
            ->where('target_id', $instance->id)->where('node_id', $launch->node_id)->where('triggered_by', $launch->triggered_by)
            ->where('idempotency_key', 'like', 'conn-launch:'.$launch->id.':restore-wake:%')->latest('id')->first();
    }

    public function restoreWake(Operation $launch, Instance $instance, ?Operation $previous): void
    {
        $key = self::key($launch, 'restore-wake', predecessor: $previous?->id);
        $this->keyed(InstanceSleep::Kind, $key,
            fn (): Operation => $this->sleep->start($instance, $launch->triggered_by, $launch->request_id));
    }

    public function observeCompletion(Operation $launch): ?Operation
    {
        $recorded = ConnLaunchChild::Completion->operation($launch);
        $completion = ConnLaunchChild::completions()->where('target_id', ConnLaunchChild::instanceId($launch))
            ->where('node_id', $launch->node_id)->where('id', '>', $recorded->id ?? 0)->latest('id')->first();

        return $completion === null ? $recorded : $this->state->observeCompletion($launch->id, $completion);
    }

    public function complete(Operation $launch, Instance $instance): Operation
    {
        $key = self::key($launch, 'complete', 'application');
        $retry = Arr::has($launch->state ?? [], 'launch_retry_requests.application');

        return $this->keyed(StartInstanceCompleteAction::Kind, $key, fn (): Operation => $this->record($launch, ConnLaunchChild::Completion, $key,
            function () use ($launch, $instance, $retry): Operation {
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
            }));
    }

    public function retryExistingApplication(Operation $launch, Instance $instance, string $retryId): void
    {
        $key = self::key($launch, 'complete', 'application');
        $this->keyed(StartInstanceCompleteAction::Kind, $key, fn (): ?Operation => $this->state->admitExistingApplicationRetry(
            $launch->id, $retryId, $key, fn (Operation $parent): array => [
                'operation' => $this->complete->execute($instance, $parent->triggered_by, $parent->request_id),
                'owned' => true,
            ]));
    }

    public function destroy(Operation $launch, Instance $instance): Operation
    {
        $create = ConnLaunchChild::Create->operation($launch);
        $previous = $this->ownedDestroy($launch);
        $unstarted = $create !== null && self::unstartedReservation($instance, $create);
        if (! $unstarted) {
            $this->assertCleanupSafe($instance, $previous);
        }
        $key = self::key($launch, 'destroy', predecessor: $previous?->id);

        return $this->keyed(InstanceDestroyKind::forInstance($instance), $key,
            fn (): Operation => $this->destroy->execute($instance, true, $launch->triggered_by, $launch->request_id,
                within: fn (Closure $record): Operation => $this->state->admitChild($launch->id, ConnLaunchChild::Destroy, $key,
                    function (Operation $parent) use ($instance, $create, $previous, $unstarted, $record): array {
                        $target = Instance::query()->lockForUpdate()->findOrFail($instance->id);
                        $owned = ConnLaunchChild::Create->operation($parent);
                        if ($owned === null || $owned->id !== $create?->id || ($owned->state['conn_launch_owner'] ?? null) !== $parent->id
                            || $target->source_prepare_id === null || $target->source_prepare_id !== ($owned->input['prepare_id'] ?? null)
                            || ConnLaunchChild::Destroy->operationId($parent) !== $previous?->id
                            || ($target->status === InstanceState::Removing && $previous === null)
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

    public function usedByAnotherLaunch(Operation $launch, Instance $instance, ?DateTimeInterface $since = null): bool
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

    private function assertCleanupSafe(Instance $instance, ?Operation $previous): void
    {
        $work = $this->work->read($instance);
        $stoppedByRemoval = ($work['reason'] ?? null) === 'stopped' && $instance->status === InstanceState::Removing
            && ($previous?->state['container_stop_succeeded'] ?? null) === true;
        if ($stoppedByRemoval) {
            return;
        }
        $activity = $work['state'] === 'clean' ? $this->activity->read($instance) : null;
        if ($activity === null || ! $activity->readable || $activity->turnRuns || $activity->lastActivityUnix !== null) {
            throw new ResourceOperationException('instance.unsaved_work', 'The Instance may hold work from another caller.', 409);
        }
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
    private function keyed(string $kind, string $key, Closure $admit): mixed
    {
        return $this->idempotency->under($kind, $key, hash('sha256', $kind."\0".$key), $admit);
    }

    public static function key(Operation $launch, string $phase, ?string $retry = null, ?int $predecessor = null): string
    {
        $number = $retry === null ? $predecessor : Arr::get($launch->state ?? [], 'launch_last_retry.'.$retry.'.id');

        return 'conn-launch:'.$launch->id.':'.$phase.':'.(is_string($number) || is_int($number) ? $number : 'initial');
    }
}
