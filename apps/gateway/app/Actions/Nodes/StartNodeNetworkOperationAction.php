<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\NodeNetworkConvergeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** One direct peer root; its DAG owns the peer and hub steps. */
final readonly class StartNodeNetworkOperationAction
{
    public function __construct(private StartOperationAction $operations, private NodeNetworkConvergeInput $network) {}

    public function execute(Node $node, bool $rolelessOperator = false, ?string $requestId = null): Operation
    {
        if ($node->status !== LifecycleStatus::Active || $node->platform !== 'linux'
            || $node->roles()->where('role', RoleName::Vpn)->exists()) {
            throw new ResourceOperationException('node.network_unsupported', 'Peer convergence requires an active Linux peer.', 422);
        }
        $intent = $this->network->capture($node, $rolelessOperator);

        return $this->operations->execute('node.network_converge', 'node', $node->id, $node->id,
            ['intent' => $intent, 'participant_node_ids' => array_values(array_unique([209, $node->id, ValidatedData::integer($intent['hub_id'])]))], 'node-network', $requestId);
    }
}
