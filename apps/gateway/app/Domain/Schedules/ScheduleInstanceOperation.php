<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

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
use App\Models\Schedule;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records owned runtime children. Runtime execution and durable waiting stay in Swamp. */
final readonly class ScheduleInstanceOperation implements OperationChildAdmission, OperationCompletion, OperationFamilyChildren, OperationFamilyExecution, OperationGatewayCommand
{
    public function __construct(private StartOperationAction $operations, private InstantiateProjectRuntimeDefinitionsAction $definitions, private OperationChildren $children) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        $id = $intent['input']['instance_id'] ?? null;
        if (! is_int($id) || ! OperationChildIntent::same($intent['input'], ['instance_id' => $id])
            || ! in_array($intent['kind'], ['schedule.install_instance_definitions'], true)
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
            if ($instance->placedOnAppProd()) {
                $instance = $this->definitions->captureForClone($instance);
            }
            $plan = $this->plan($instance, $intent['kind']);
            if ($plan !== []) {
                app(ScheduleSwampSafety::class)->assertAllowed($instance->node);
            }
            $operation = $this->operations->execute($intent['kind'], 'instance', $id, $parent->node_id, ['instance_id' => $id], 'runtime', null);
            $operation->update(['state' => [...($operation->state ?? []), 'runtime_children' => $plan]]);

            return $operation;
        });
    }

    /** @return list<array{key:string,kind:string,node_id:int,input:array<string,mixed>}> */
    public function plan(Instance $instance, string $kind): array
    {
        if (! in_array($kind, ['schedule.install_instance_definitions'], true)) {
            $this->refuse();
        }
        $query = Schedule::query()->whereIn('target_type', Instance::morphTypes())->where('target_id', $instance->id);
        $query->whereNotNull('source_definition_id')->whereNotIn('status', [LifecycleStatus::Active, LifecycleStatus::Removing]);
        $plan = [];
        foreach ($query->orderBy('id')->limit(4097)->get() as $record) {
            if (count($plan) >= 4096) {
                throw new ResourceOperationException('operation.runtime_limit', 'An Instance runtime plan may contain at most 4096 children.', 409);
            }
            $plan[] = ['key' => 'runtime-'.count($plan), 'kind' => 'schedule.create',
                'node_id' => $instance->node_id, 'input' => ['id' => $record->id]];
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

        return ['descriptors' => [['key' => 'runtime-ready', 'step' => 'runtime-ready', 'transport' => 'gateway', 'name' => 'schedule.instance_runtime_ready',
            'input' => ['instance_id' => $operation->target_id], 'resumable' => true, 'required' => true]],
            'result' => ['runtime_child_count' => count($plan)]];
    }

    public function input(Operation $operation, array $run): array
    {
        return ['instance_id' => $operation->target_id];
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        $this->assertChildren($operation);
    }

    private function assertChildren(Operation $operation): void
    {
        foreach ($this->childContext($operation)['children'] as $intent) {
            $child = $this->children->requireSucceeded($operation, $intent['key']);
            if ($child->kind !== 'schedule.create' || $child->node_id !== $operation->node_id
                || $child->target_type !== 'instance_schedules' || $child->target_id !== $operation->target_id
                || ($child->input['id'] ?? null) !== ($intent['input']['id'] ?? null)) {
                throw new ResourceOperationException('operation.child_unproven', 'The runtime child does not prove the selected Schedule.', 409);
            }
        }
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
        $this->assertChildren($operation);

        return ['ready' => true];
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status === OperationStatus::Succeeded) {
            $this->assertChildren($operation);
        }
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void {}

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
