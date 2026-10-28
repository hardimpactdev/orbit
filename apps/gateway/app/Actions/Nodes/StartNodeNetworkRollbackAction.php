<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** A compensation owns its pinned connection and can restore after its parent is cancelled. */
final readonly class StartNodeNetworkRollbackAction
{
    public function __construct(private StartOperationAction $operations, private OperationIdempotency $idempotency, private OperationChildren $children) {}

    public function execute(Operation $parent): Operation
    {
        if (($existing = $this->idempotency->replay('node.network_rollback')) !== null) {
            return $existing;
        }
        if ($this->idempotency->parent?->id !== $parent->id || $this->idempotency->childKey !== 'role-network-rollback'
            || $parent->kind !== 'node.role_add' || ($parent->state['role_first_managed'] ?? false) !== true) {
            throw new ResourceOperationException('operation.child_forbidden', 'Peer rollback requires its owning first-role parent.', 422);
        }
        $network = $this->children->requireSucceeded($parent, 'role-network');
        if ($network->kind !== 'node.network_converge' || $network->node_id !== $parent->node_id || ($network->result['retained'] ?? null) !== true) {
            throw new ResourceOperationException('operation.child_forbidden', 'The peer has no proven retained transaction.', 422);
        }
        $input = array_intersect_key($network->input, array_flip(['agent_pin', 'bootstrap_host', 'bootstrap_port', 'bootstrap_user', 'bootstrap_fingerprint', 'bootstrap_identity']));

        return $this->operations->execute('node.network_rollback', 'node', $parent->target_id, $parent->node_id,
            [...$input, 'command_input' => []], 'node-network', $parent->request_id);
    }
}
