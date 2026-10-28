<?php

declare(strict_types=1);

namespace App\Domain\Instances\StepLists;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** The list deadline and cleanup reserve come from server time and parent kind. */
final readonly class InstanceStepListBudget
{
    public function remaining(Operation $operation): int
    {
        $started = $operation->started_at ?? throw new ResourceOperationException('operation.completion_unproven', 'Claim the list before preparing a command budget.', 409);
        $deadline = $started->getTimestamp() + 550;
        $parentId = $operation->getAttribute('parent_operation_id');
        $parent = is_int($parentId) ? Operation::query()->findOrFail($parentId) : null;
        if ($parent?->started_at !== null) {
            $reserve = $parent->kind === 'instance.create' ? ($operation->input['list'] === 'setup' ? 150 : 90) : 0;
            $deadline = min($deadline, $parent->started_at->getTimestamp() + 550 - $reserve);
            if ($parent->kind === 'instance.create' && $operation->input['list'] === 'teardown') {
                $deadline = min($deadline, $started->getTimestamp() + 60);
            }
        }

        return max(0, $deadline - now()->getTimestamp());
    }
}
