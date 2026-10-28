<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Nodes\NodeData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Finalizes assignment state only. Integration children keep its credentials and Settings. */
final readonly class NodeRoleOperationCompletion implements OperationCompletion
{
    public function __construct(private NodeRoleOperationExecution $execution, private RecordEventBroadcaster $broadcaster) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $intent = ValidatedData::object($operation->state['role_intent'] ?? []);
        $assignment = NodeRole::query()->findOrFail(ValidatedData::integer($intent['assignment_id'] ?? null));
        if ($assignment->node_id !== $operation->node_id || $assignment->role->value !== ($intent['role'] ?? null)) {
            throw new ResourceOperationException('operation.completion_unproven', 'The admitted role assignment changed.', 409);
        }
        if ($outcome->status === OperationStatus::Succeeded) {
            $this->execution->assertCompletion($operation, $outcome);
            $assignment->markConvergenceActive();
        } else {
            $step = $outcome->result['failed_step'] ?? 'operation';
            $assignment->markConvergenceFailed(is_string($step) ? $step : 'operation', $outcome->errorCode ?? 'operation.cancelled');
        }
        $node = $assignment->node->refresh();
        $this->broadcaster->broadcast(RecordEventType::NodeUpdated, $node->id, NodeData::fromModel($node)->toArray());
    }
}
