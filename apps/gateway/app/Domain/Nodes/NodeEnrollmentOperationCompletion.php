<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Nodes\NodeData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Publishes proven enrollment state; failure never treats an uncertain bootstrap as an observation. */
final readonly class NodeEnrollmentOperationCompletion implements OperationCompletion
{
    public function __construct(private NodeLifecycleOperationExecution $execution, private RecordEventBroadcaster $broadcaster) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $this->execution->assertCompletion($operation, $outcome);
        $node = Node::query()->lockForUpdate()->findOrFail($operation->node_id);
        $intent = ValidatedData::object($operation->state['enrollment_intent'] ?? []);
        if ($outcome->status === OperationStatus::Succeeded) {
            if ($node->status !== LifecycleStatus::Active) {
                throw new ResourceOperationException('operation.completion_unproven', 'Enrollment requires its proven activation stage.', 409);
            }
        } else {
            $prior = is_array($intent['prior_state'] ?? null) ? $intent['prior_state'] : [];
            $restored = $operation->children()->where('child_key', 'add-restore')->where('status', OperationStatus::Succeeded)->exists();
            $began = $operation->children()->where('child_key', 'add-begin')->where('status', OperationStatus::Succeeded)->exists();
            if (! $restored && ($began || ($intent['existed'] ?? false) !== true)) {
                $node->update(['status' => LifecycleStatus::Failed,
                    'failed_step' => ValidatedData::string($outcome->result['failed_step'] ?? 'operation'),
                    'error_code' => $outcome->errorCode ?? 'node.provision_failed']);
            } elseif ($restored && $node->status->value !== ($prior['status'] ?? null)) {
                throw new ResourceOperationException('operation.completion_unproven', 'Enrollment restoration has no matching prior lifecycle state.', 409);
            }
        }
        $this->broadcaster->broadcast(($intent['existed'] ?? false) === true ? RecordEventType::NodeUpdated : RecordEventType::NodeCreated,
            $node->id, NodeData::fromModel($node->refresh()->load('roles'))->toArray());
    }
}
