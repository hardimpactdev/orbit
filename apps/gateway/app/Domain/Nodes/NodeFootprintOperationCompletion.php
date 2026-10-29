<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Fleet\NodeFootprintPlan;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeFootprint;
use App\Models\Operation;

/** Records the digests proven by this attempt, retaining unreached state after a failure. */
final readonly class NodeFootprintOperationCompletion implements OperationCompletion
{
    public function __construct(private NodeFootprintEvidence $evidence) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if (($operation->state['node_footprint_prepared'] ?? false) !== true) {
            if ($outcome->status === OperationStatus::Succeeded) {
                throw new ResourceOperationException('operation.completion_unproven', 'The footprint was not prepared.', 409);
            }

            return;
        }
        $derived = $this->evidence->derive($operation, Operation::query()->where('parent_operation_id', $operation->id)->get(), $outcome->status !== OperationStatus::Succeeded);
        if ($outcome->status === OperationStatus::Succeeded && $derived['error_code'] !== null) {
            throw new ResourceOperationException('operation.completion_unproven', 'The final footprint result does not match its receipts.', 409);
        }
        $plan = NodeFootprintPlan::of($derived['recorded']);
        NodeFootprint::query()->updateOrCreate(['node_id' => $operation->node_id], [
            'digest' => $plan->digest(), 'artifacts' => $plan->artifacts, 'converged_at' => now(),
        ]);
    }
}
