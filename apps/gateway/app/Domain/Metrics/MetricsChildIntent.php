<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Reads only children durably admitted by the generic executor. */
final readonly class MetricsChildIntent
{
    public function find(Operation $parent, string $key): ?Operation
    {
        if (! $parent->children()->where('child_key', $key)->exists()) {
            return null;
        }

        return app(OperationChildren::class)->child($parent, $key);
    }

    public function snapshot(Operation $operation): Operation
    {
        $source = Operation::query()->find($operation->input['source_operation_id'] ?? null);
        if (! $source instanceof Operation || $source->node_id !== $operation->node_id || $source->status !== OperationStatus::Succeeded) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The restore has no successful snapshot on its target Node.', 422);
        }
        $parentId = $operation->getAttribute('parent_operation_id');
        if (! is_int($parentId) || $source->getAttribute('parent_operation_id') !== $parentId
            || $source->getAttribute('child_key') !== ValidatedData::string($operation->input['source_key'])
            || $operation->getAttribute('child_key') !== ValidatedData::string($operation->input['restore_key'])) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The restore and snapshot must belong to the same parent.', 422);
        }

        $parent = Operation::query()->findOrFail($parentId);
        $proven = app(OperationChildren::class)->requireSucceeded($parent, ValidatedData::string($operation->input['source_key']));
        if ($proven->id !== $source->id) {
            throw new ResourceOperationException('operation.child_unproven', 'The restore names another proven snapshot.', 409);
        }

        return $proven;
    }
}
