<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\ContainerProcesses;
use App\Domain\Instances\InstanceCompleteProjection;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Routes\NativeRouteAdmission;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Admits `instance.complete`, the second Operation of a clone create (docs/reference/development-containers.md#complete-a-clone): the
 * database, a nested fleet, the services, the Route and a check. The command, the API and the create's terminal projection all call it.
 */
final readonly class StartInstanceCompleteAction
{
    public const string Kind = 'instance.complete';

    public function __construct(private StartOperationAction $operations, private OperationIdempotency $idempotency) {}

    public function execute(Instance $instance, ?string $caller = null, ?string $requestId = null): Operation
    {
        if (($replay = $this->idempotency->replay(self::Kind)) !== null) {
            return $replay;
        }

        return DB::transaction(function () use ($instance, $caller, $requestId): Operation {
            // A retry installs the Processes from the Project's development definitions as they are now, as a create retry does; the
            // first completion, which the create's projection admits, keeps the ones its create admitted.
            app(InstantiateProjectRuntimeDefinitionsAction::class)->captureForDevelopment(Instance::query()->lockForUpdate()->findOrFail($instance->id));

            return $this->admit($instance, $caller, $requestId);
        });
    }

    /**
     * Admits inside the caller's transaction, so a create's projection can end the create and take the Instance lock for the completion
     * in one commit. A clone that is not `source_resolved` with its starting commit and a registered T3 server is refused.
     */
    public function admit(Instance $instance, ?string $caller, ?string $requestId): Operation
    {
        $instance = Instance::query()->with(['node', 'project'])->lockForUpdate()->findOrFail($instance->id);
        $runtime = $instance->runtime()->first();
        if ($runtime === null || $runtime->clone_source_instance_id === null) {
            self::refuse('Only a container clone can be completed.');
        }
        if ($instance->status !== InstanceState::SourceResolved || $instance->starting_commit === null) {
            self::refuse("Instance [{$instance->name}] is {$instance->status->value}; only a clone whose create checked out its workspace can be completed.");
        }
        if (! app(InstanceLifecycleContributions::class)->registered($instance)) {
            self::refuse("The T3 server of Instance [{$instance->name}] is not registered.");
        }
        $create = Operation::query()->where('kind', 'instance.create')->where('target_type', 'instance')->where('target_id', $instance->id)
            ->orderByDesc('id')->first();
        $admitted = $create?->input['direct_provisioning'] ?? null;
        if (! $create instanceof Operation || ! $create->status->terminal() || ! is_array($admitted) || ! is_array($admitted['container'] ?? null)) {
            self::refuse('The clone has no ended create to complete.');
        }
        $input = ['instance_id' => $instance->id, 'project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'name' => $instance->name,
            'create_operation_id' => $create->id, 'route_id' => $create->input['route_id'] ?? null,
            'ssh_execution' => $create->input['ssh_execution'],
            // The create's admitted database plan; the Processes as they are now, which the run's input checks again at its start.
            'direct_provisioning' => ['instance_id' => $instance->id, 'database' => $admitted['database'] ?? null,
                'server_database' => $admitted['server_database'] ?? null, 'server_valkey' => $admitted['server_valkey'] ?? null,
                'container' => [...ValidatedData::object($admitted['container']), 'processes' => app(ContainerProcesses::class)->units($instance)]]];
        if ($input['route_id'] !== null) {
            // The Route publication from the current site catalog, as a create captures it; the run holds the publication Nodes.
            $route = Route::query()->findOrFail(ValidatedData::integer($input['route_id']));
            $input = [...$input, ...app(NativeRouteAdmission::class)->capture('route.create', $route, ['mode' => 'converge'], $instance->node_id,
                preserveEnvironment: true, preparedWorkloads: true)];
        }

        return $this->operations->execute(self::Kind, 'instance', $instance->id, $instance->node_id, $input, $caller, $requestId,
            lockKey: OperationStore::lockKey('instance', $instance->id));
    }

    /**
     * A completion that the create's projection could not admit: recorded as a failed `instance.complete` at once, in the projection's
     * transaction, with the recovery of a failed run. So the create names it, and Conn, the alerts and a task copy see an ordinary failed
     * completion. No run starts and no lock is taken.
     */
    public function refused(Instance $instance, Operation $create, string $code, string $detail): Operation
    {
        $store = app(OperationStore::class);
        $operation = app(OperationIdempotency::class)->suspended(fn (): Operation => $store->create(self::Kind, 'instance', $instance->id, $instance->node_id,
            ['instance_id' => $instance->id, 'create_operation_id' => $create->id, 'route_id' => $create->input['route_id'] ?? null],
            $create->triggered_by, $create->request_id));
        $instance->update(['failed_step' => 'complete', 'error_code' => $code]);
        $store->change($operation, ['status' => OperationStatus::Failed, 'error_code' => $code, 'failed_step' => 'complete',
            'started_at' => now(), 'finished_at' => now(), 'lock_key' => null,
            'result' => ['instance_id' => $instance->id, 'ready' => false, 'failed_step' => 'complete', 'error_code' => $code,
                'recovery' => InstanceCompleteProjection::recovery($instance)],
            'state' => [...($operation->state ?? []), 'native_failure' => ['failed_step' => 'complete', 'detail' => $detail, 'stderr_recorded' => false]]]);

        return $operation->refresh();
    }

    public static function refuse(string $message): never
    {
        throw new ResourceOperationException('instance.complete_unavailable', $message, 409);
    }
}
