<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** Reads only children durably admitted by the generic executor. */
final readonly class MetricsChildIntent
{
    public function find(Operation $parent, string $key): ?Operation
    {
        $saved = $parent->state['executor_children'][$key] ?? null;
        if (! is_array($saved) || ! is_int($saved['id'] ?? null)) {
            return null;
        }
        return Operation::query()->find($saved['id']);
    }

    public function snapshot(Operation $operation): Operation
    {
        $source = Operation::query()->find($operation->input['source_operation_id'] ?? null);
        if (! $source instanceof Operation || $source->node_id !== $operation->node_id || $source->status !== \App\Domain\Operations\OperationStatus::Succeeded) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The restore has no successful snapshot on its target Node.', 422);
        }
        $parent = Operation::query()->whereJsonContains('state->executor_children->'.$operation->input['source_key'].'->id', $source->id)->first();
        if (! $parent instanceof Operation || $this->find($parent, $operation->input['restore_key'])?->id !== $operation->id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The restore and snapshot must belong to the same parent.', 422);
        }
        return $source;
    }
}
