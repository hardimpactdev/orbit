<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\ContainerProcesses;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationIdempotency;
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

        return DB::transaction(fn (): Operation => $this->admit($instance, $caller, $requestId));
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

    public static function refuse(string $message): never
    {
        throw new ResourceOperationException('instance.complete_unavailable', $message, 409);
    }
}
