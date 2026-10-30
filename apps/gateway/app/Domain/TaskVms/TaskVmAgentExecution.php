<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\TaskVms\StartTaskVmIncusEffectAction;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\TaskVms\TaskVmPreservation;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Pure target/intent checks before the shared keyed agent transport runs. */
final readonly class TaskVmAgentExecution implements OperationFamilyExecution
{
    public function context(Operation $operation): array
    {
        $kind = app(OperationKinds::class)->definition($operation->kind);
        if (! is_array($kind) || ! isset($kind['runs'], $kind['completion_contract'])) {
            throw new \LogicException('The VM kind has no Swamp contract.');
        }
        $contract = ValidatedData::object($kind['completion_contract']);
        $run = $kind['runs'][0];
        $step = ValidatedData::string($contract['step']);
        $name = ValidatedData::string($contract['name']);
        $fields = ValidatedData::stringList($run['input_fields'] ?? []);
        $values = ValidatedData::object($run['input_values'] ?? []);

        return ['descriptors' => [['key' => $step, 'step' => $step, 'transport' => 'agent', 'name' => $name,
            'input' => [...Arr::only($operation->input, $fields), ...$values], 'required' => true, 'resumable' => true]]];
    }

    public function input(Operation $operation, array $run): array
    {
        return $this->context($operation)['descriptors'][0]['input'];
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        $this->assertIntent($operation);
    }

    public function assertIntent(Operation $operation): void
    {
        if ($operation->target_type !== 'task_vm') {
            $this->refuse();
        }
        $vm = TaskVm::query()->findOrFail($operation->target_id);
        if (in_array($operation->kind, ['task_vm.cache_initialize', 'task_vm.cache_mount'], true)) {
            $host = app(TaskVmSettings::class)->host((int) $vm->host_node_id);
            if ($vm->host_node_id !== $operation->node_id || $vm->state !== TaskVmState::Ready || ($operation->kind === 'task_vm.cache_mount' && (($operation->input['project'] ?? null) !== $host->project || ($operation->input['name'] ?? null) !== $vm->name))) {
                $this->refuse();
            }
        } elseif (str_starts_with($operation->kind, 'task_vm.incus_')) {
            if ($vm->provider !== TaskVmProviderKind::Incus || $vm->host_node_id !== $operation->node_id) {
                $this->refuse();
            }
            $verb = substr($operation->kind, strlen('task_vm.incus_'));
            $expected = app(StartTaskVmIncusEffectAction::class)->snapshot($vm, $verb);
            foreach (['task_vm_id', 'vm', 'operation'] as $field) {
                if ($this->ordered($operation->input[$field] ?? null) !== $this->ordered($expected[$field])) {
                    $this->refuse();
                }
            }
            if ($verb === 'delete') {
                app(TaskVmPreservation::class)->assertDeletion($operation, $vm);
            }
        } else {
            if ($vm->node_id !== $operation->node_id || $vm->node?->user !== ($operation->input['user'] ?? $vm->node?->user)) {
                $this->refuse();
            }
        }
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.execution_forbidden', 'The agent effect does not belong to this VM.', 422);
    }

    private function ordered(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as &$entry) {
            $entry = $this->ordered($entry);
        }
        unset($entry);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
