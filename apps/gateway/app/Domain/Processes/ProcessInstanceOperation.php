<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records owned runtime children. Runtime execution and durable waiting stay in Swamp. */
final readonly class ProcessInstanceOperation implements OperationChildAdmission, OperationCompletion, OperationFamilyChildren, OperationFamilyExecution, OperationGatewayCommand
{
    public function __construct(private StartOperationAction $operations, private InstantiateProjectRuntimeDefinitionsAction $definitions, private OperationChildren $children) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        $id = $intent['input']['instance_id'] ?? null;
        if (! is_int($id) || ! OperationChildIntent::same($intent['input'], ['instance_id' => $id])
            || ! in_array($intent['kind'], ['process.install_instance_definitions', 'process.restart_instance'], true)
            || $parent->target_type !== 'instance' || $parent->target_id !== $id || $intent['node_id'] !== $parent->node_id) {
            $this->refuse();
        }
        if (! isset(OperationKinds::definitions()[$intent['kind']])) {
            throw new ResourceOperationException('operation.kind_unavailable', 'This runtime kind needs the shared child contracts.', 409);
        }

        return DB::transaction(function () use ($parent, $intent, $id): Operation {
            $instance = Instance::query()->with('node')->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($instance->node_id !== $parent->node_id) {
                $this->refuse();
            }
            if ($intent['kind'] === 'process.install_instance_definitions' && $instance->placedOnAppProd()) {
                $instance = $this->definitions->captureForClone($instance);
            }
            $plan = $this->plan($instance, $intent['kind']);
            $operation = $this->operations->execute($intent['kind'], 'instance', $id, $parent->node_id, ['instance_id' => $id], 'runtime', null);
            $operation->update(['state' => [...($operation->state ?? []), 'runtime_children' => $plan]]);

            return $operation;
        });
    }

    /** @return list<array{key:string,kind:string,node_id:int,input:array<string,mixed>}> */
    public function plan(Instance $instance, string $kind): array
    {
        if (! in_array($kind, ['process.install_instance_definitions', 'process.restart_instance'], true)) {
            $this->refuse();
        }
        $query = Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id);
        if ($kind === 'process.restart_instance') {
            $query->where('status', LifecycleStatus::Active)->where('desired_state', DesiredProcessState::Running)->where('restart_on_deploy', true);
        } else {
            $query->whereNotNull('source_definition_id')->whereNotIn('status', [LifecycleStatus::Active, LifecycleStatus::Removing]);
        }
        $plan = [];
        foreach ($query->orderBy('id')->get() as $record) {
            $plan[] = ['key' => 'runtime-'.count($plan), 'kind' => $kind === 'process.restart_instance' ? 'process.restart' : 'process.create',
                'node_id' => $instance->node_id, 'input' => ['process_id' => $record->id]];
        }

        return $plan;
    }

    public function childContext(Operation $parent): array
    {
        $this->instance($parent);
        $plan = $parent->state['runtime_children'] ?? null;
        if (! is_array($plan) || ! array_is_list($plan)) {
            $this->refuse();
        }
        $children = [];
        foreach ($plan as $entry) {
            $entry = ValidatedData::object($entry);
            $children[] = ['key' => ValidatedData::string($entry['key']), 'kind' => ValidatedData::string($entry['kind']),
                'node_id' => ValidatedData::integer($entry['node_id']), 'input' => ValidatedData::object($entry['input'])];
        }

        return ['intent' => [], 'children' => $children];
    }

    public function context(Operation $operation): array
    {
        $plan = $this->childContext($operation)['children'];

        return ['descriptors' => [['key' => 'runtime-ready', 'step' => 'runtime-ready', 'transport' => 'gateway', 'name' => 'process.instance_runtime_ready',
            'input' => ['instance_id' => $operation->target_id], 'resumable' => true, 'required' => true]],
            'result' => ['runtime_child_count' => count($plan)]];
    }

    public function input(Operation $operation, array $run): array
    {
        return ['instance_id' => $operation->target_id];
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        $this->processIds($operation);
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $receipt;
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['instance_id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['instance_id'], 'additionalProperties' => false];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $ids = $this->processIds($operation);

        return ['ready' => true, ...($operation->kind === 'process.restart_instance' ? ['process_ids' => $ids] : [])];
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status === OperationStatus::Succeeded) {
            $ids = $this->processIds($operation);
            if ($operation->kind === 'process.restart_instance' && ($outcome->result['process_ids'] ?? null) !== $ids) {
                throw new ResourceOperationException('operation.completion_unproven', 'The restarted Process IDs do not match the proven children.', 409);
            }
        }
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void {}

    /** @return list<int> */
    private function processIds(Operation $operation): array
    {
        $ids = [];
        foreach ($this->childContext($operation)['children'] as $intent) {
            $child = $this->children->requireSucceeded($operation, $intent['key']);
            if ($child->kind !== $intent['kind'] || $child->target_type !== 'process'
                || $child->target_id !== ($intent['input']['process_id'] ?? null) || $child->node_id !== $operation->node_id
                || in_array($child->target_id, $ids, true)) {
                throw new ResourceOperationException('operation.child_unproven', 'The runtime child does not prove the selected Process.', 409);
            }
            $ids[] = $child->target_id;
        }

        return $ids;
    }

    private function instance(Operation $operation): Instance
    {
        $parent = $operation->parent_operation_id === null ? null : Operation::query()->whereKey($operation->parent_operation_id)->first();
        $id = $operation->input['instance_id'] ?? null;
        if ($parent === null || $operation->child_key === null || ! is_int($id)
            || ! OperationChildIntent::same($operation->input, ['instance_id' => $id])
            || $operation->target_type !== 'instance' || $operation->target_id !== $id
            || $parent->target_type !== 'instance' || $parent->target_id !== $id || $parent->node_id !== $operation->node_id) {
            $this->refuse();
        }
        $instance = Instance::query()->whereKey($id)->firstOrFail();
        if ($instance->node_id !== $operation->node_id) {
            $this->refuse();
        }

        return $instance;
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.child_forbidden', 'The runtime child does not match its bound Instance and Node.', 422);
    }
}
