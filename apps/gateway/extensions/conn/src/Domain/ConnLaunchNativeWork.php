<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\NativeRunCancellation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStore;
use App\Infrastructure\Swamp\SwampScheduleWorkflows;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use stdClass;

final readonly class ConnLaunchNativeWork
{
    public function __construct(
        private ConnLaunchState $state,
        private OperationStore $store,
        private StartOperationAction $start,
        private OperationIdempotency $idempotency,
        private SwampScheduleWorkflows $vault,
        private NativeRunCancellation $cancellation,
    ) {}

    public function inspect(Operation $launch, Instance $instance, ConnEnvironment $environment, ?stdClass $model): ?Operation
    {
        $key = ConnLaunchInstances::key($launch, 'inspect', 'launch');

        return DB::transaction(function () use ($launch, $instance, $environment, $model, $key): ?Operation {
            $parent = $this->state->locked($launch->id);
            $state = $parent->state ?? [];
            if ($parent->cancel_requested_at !== null || $parent->status->terminal() || isset($state['dispatch_committed_at'])
                || ($state['launch_phase'] ?? null) === 'needs_retry') {
                return null;
            }
            $previous = self::attempt($parent, 'launch_inspect_operation_id');
            if ($previous !== null && ($previous->idempotency_key === $key || ! $this->cancellation->settled($previous))) {
                return $previous;
            }
            $provider = $model->instanceId ?? $model->provider ?? null;
            $intent = ['action' => 'inspect-launch', 'environment_id' => $environment->environment_id,
                ...(is_string($provider) ? ['requested_provider' => $provider] : [])];
            $operation = $this->admit($parent, $instance, OperationKinds::ConnLaunchInspect, $key, $intent);
            $this->store->change($operation, ['state' => [...($operation->state ?? []), 'conn_launch_owner' => $parent->id]]);
            $state['launch_inspect_operation_id'] = $operation->id;
            $this->store->change($parent, ['state' => $state]);

            return $operation;
        });
    }

    public function dispatch(Operation $launch, Instance $instance, string $environmentId, string $incarnation,
        string $projectId, stdClass $model): ?Operation
    {
        return $this->state->admitDispatch($launch->id, $environmentId, $incarnation, $projectId, $model,
            fn (Operation $parent, bool $recover): Operation => $this->admit($parent, $instance, OperationKinds::ConnLaunchDispatch,
                ConnLaunchInstances::key($parent, $recover ? 'reconcile' : 'dispatch', 'launch'),
                ['action' => $recover ? 'reconcile-launch' : 'launch']));
    }

    /** @param array<string, mixed> $intent */
    private function admit(Operation $launch, Instance $instance, string $kind, string $key, array $intent): Operation
    {
        $runtime = $instance->runtime()->firstOrFail();
        $input = ['launch_operation_id' => $launch->id, 'intent' => [
            ...$intent, 'instance_id' => $instance->id,
            'runtime' => ['project' => $runtime->incus_project, 'name' => $runtime->container_name, 'program' => $runtime->server()->program()],
        ]];

        return $this->idempotency->under($kind, $key, hash('sha256', $kind."\0".$key),
            fn (): Operation => $this->start->execute($kind, 'instance', $instance->id, $launch->node_id, $input,
                $launch->triggered_by, $launch->request_id, lockKey: OperationStore::lockKey('conn_launch', $launch->id)));
    }

    public static function attempt(Operation $launch, string $field): ?Operation
    {
        $id = $launch->state[$field] ?? null;
        if (! is_int($id)) {
            return null;
        }
        $kind = $field === 'launch_inspect_operation_id' ? OperationKinds::ConnLaunchInspect : OperationKinds::ConnLaunchDispatch;

        return Operation::query()->where('kind', $kind)->where('node_id', $launch->node_id)
            ->where('triggered_by', $launch->triggered_by)->where('target_type', 'instance')
            ->where('target_id', ConnLaunchChild::instanceId($launch))->where('input->launch_operation_id', $launch->id)->findOrFail($id);
    }

    /** Remove the execution copy for the exact settled dispatch before any recovery or final publication. */
    public function clearDispatchCopy(Operation $launch, Operation $dispatch): void
    {
        if (($launch->state['launch_dispatch_copy_removed'] ?? null) === $dispatch->id) {
            return;
        }
        $this->vault->deleteSecrets(ConnLaunchNativeProjection::Vault, [ConnLaunchNativeProjection::executionCopyKey($dispatch)]);
        DB::transaction(function () use ($launch, $dispatch): void {
            $parent = $this->state->locked($launch->id);
            if (($parent->state['launch_dispatch_operation_id'] ?? null) === $dispatch->id) {
                $this->store->change($parent, ['state' => [...($parent->state ?? []), 'launch_dispatch_copy_removed' => $dispatch->id]]);
            }
        });
    }
}
