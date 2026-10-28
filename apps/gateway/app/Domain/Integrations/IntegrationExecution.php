<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** The integrations lane's admitted sequence and its required receipts. No Node work runs here. */
final readonly class IntegrationExecution implements OperationFamilyExecution
{
    public function context(Operation $operation): array
    {
        return ['descriptors' => array_values($operation->input['effects'])];
    }

    public function input(Operation $operation, array $run): array
    {
        $descriptor = $operation->input['effects'][$run['key']] ?? null;
        if (! is_array($descriptor) || $descriptor['transport'] !== $run['transport'] || $descriptor['name'] !== $run['name']) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This effect is outside the integration intent.', 422);
        }

        return $descriptor['input'];
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        foreach ($operation->input['flow'] as $entry) {
            if ($entry['type'] === 'run' && $entry['key'] === $run['key']) {
                return;
            }
            $this->assertEntry($operation, $entry);
        }
        throw new ResourceOperationException('operation.execution_forbidden', 'This effect is not in the integration sequence.', 422);
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status !== OperationStatus::Succeeded) {
            return;
        }
        foreach ($operation->input['flow'] as $entry) {
            $this->assertEntry($operation, $entry);
        }
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    /** @param array<string, mixed> $entry */
    private function assertEntry(Operation $operation, array $entry): void
    {
        if (isset($entry['when'])) {
            $condition = $entry['when'];
            $evidence = $operation->state['steps'][$condition['step']] ?? null;
            if (! is_array($evidence) || ! array_key_exists($condition['field'], $evidence)) {
                throw new ResourceOperationException('operation.completion_unproven', 'The condition has no proven step result.', 409);
            }
            if ($evidence[$condition['field']] !== $condition['equals']) {
                return;
            }
        }
        if ($entry['type'] === 'child') {
            $saved = $operation->state['executor_children'][$entry['key']] ?? null;
            $child = is_array($saved) && is_int($saved['id'] ?? null) ? Operation::query()->find($saved['id']) : null;
            if (! $child instanceof Operation || $child->kind !== $entry['kind'] || $child->node_id !== $entry['node_id']
                || $child->input !== $entry['input'] || $child->status !== OperationStatus::Succeeded) {
                throw new ResourceOperationException('operation.completion_unproven', 'The integration child has not completed its admitted intent.', 409);
            }

            return;
        }
        $descriptor = $operation->input['effects'][$entry['key']];
        $saved = $operation->state['executor_runs'][$entry['key']] ?? null;
        $result = $operation->state['steps'][$descriptor['step']] ?? null;
        if (! is_array($saved) || ($saved['transport'] ?? null) !== $descriptor['transport'] || ($saved['name'] ?? null) !== $descriptor['name']
            || ! is_array($saved['result'] ?? null) || isset($saved['result']['error_code'])
            || $saved['result'] !== $result || ! $operation->steps()->where('name', $descriptor['step'])->where('status', OperationStepStatus::Succeeded->value)->exists()) {
            throw new ResourceOperationException('operation.completion_unproven', 'The integration effect has no successful recorded receipt.', 409);
        }
    }
}
