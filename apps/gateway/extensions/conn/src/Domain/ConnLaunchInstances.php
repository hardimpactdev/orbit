<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Actions\Instances\StartDevelopmentInstanceCreateAction;
use App\Actions\Instances\StartInstanceCompleteAction;
use App\Actions\Instances\StartInstanceDestroyAction;
use App\Data\Instances\CreateInstanceData;
use App\Domain\Instances\InstanceBranchSelection;
use App\Domain\Instances\InstanceDestroyKind;
use App\Domain\Instances\Power\InstanceWake;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Closure;
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

    public function observeCompletion(Operation $launch, Instance $instance): ?Operation
    {
        $recorded = ConnLaunchChild::Completion->operation($launch);
        $completion = Operation::query()->where('kind', StartInstanceCompleteAction::Kind)
            ->where('target_type', 'instance')->where('target_id', $instance->id)->where('node_id', $launch->node_id)
            ->where('id', '>', $recorded?->id ?? 0)->latest('id')->first();
        if ($completion === null) {
            return $recorded;
        }

        return $this->state->admitChild($launch->id, ConnLaunchChild::Completion, 'observe-completion:'.$completion->id,
            static fn (): array => ['operation' => $completion, 'owned' => false]);
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

    public function destroy(Operation $launch, Instance $instance): Operation
    {
        $previous = ConnLaunchChild::Destroy->operationId($launch);
        $key = self::key($launch, 'destroy', predecessor: $previous);

        return $this->keyed(InstanceDestroyKind::forInstance($instance), $key,
            fn (): Operation => $this->destroy->execute($instance, true, $launch->triggered_by, $launch->request_id,
                within: fn (Closure $record): Operation => $this->record($launch, ConnLaunchChild::Destroy, $key, $record)));
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
