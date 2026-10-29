<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Source credentials remain available until its entire withdrawal has proof. */
final readonly class NodeRoleRelocationSourceCompletion implements OperationCompletion
{
    public function __construct(private NodeRoleOperationExecution $execution, private NodeRoleSettings $settings) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status !== OperationStatus::Succeeded) {
            return;
        }
        $this->execution->assertCompletion($operation, $outcome);
        $retained = ValidatedData::object($operation->state['role_intent'] ?? null);
        $assignment = NodeRole::query()->findOrFail(ValidatedData::integer($retained['assignment_id']));
        if ($operation->kind !== 'node.role_relocate_source' || $operation->node_id !== ($retained['source_node_id'] ?? null)
            || $assignment->node_id !== ($retained['target_node_id'] ?? null) || $assignment->role->value !== ($retained['role'] ?? null)) {
            throw new ResourceOperationException('operation.completion_unproven', 'The source cannot finalize another role transfer.', 409);
        }
        $this->settings->removeTransferred(ValidatedData::integer($retained['source_node_id']), $assignment->role);
    }
}
