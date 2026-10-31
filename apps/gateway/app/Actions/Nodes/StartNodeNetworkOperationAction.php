<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\NodeNetworkInput;
use App\Domain\Nodes\NodeWireGuardInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\NodeWorkerRollout;
use App\Models\Node;
use App\Models\Operation;

/** One direct peer root; its DAG owns the peer and hub steps. */
final readonly class StartNodeNetworkOperationAction
{
    public function __construct(private StartOperationAction $operations, private NodeNetworkInput $network, private NodeWireGuardInput $hub) {}

    public function execute(Node $node, bool $rolelessOperator = false, ?string $requestId = null): Operation
    {
        if ($node->status !== LifecycleStatus::Active || ! app(NodeWorkerRollout::class)->allows($node)
            || $node->roles()->where('role', RoleName::Vpn)->exists()) {
            throw new ResourceOperationException('node.network_unsupported', 'Peer convergence requires the exact selected Node worker.', 422);
        }
        $hub = $this->hub->capture($node);
        $executor = $hub['executor_node_id'];
        $hubId = $hub['hub_id'];

        return $this->operations->execute('node.network_converge', 'node', $node->id, $node->id,
            ['_network' => $this->network->capture($node, $rolelessOperator), '_wireguard' => $hub,
                'configuration' => ['$secret' => 'node.vpn_configuration', 'id' => $hubId],
                'participant_node_ids' => array_values(array_unique([$node->id, $hubId, $executor])),
                'private_bindings' => [
                    'configuration' => ['owner_node_id' => $hubId, 'read_node_ids' => [$executor], 'write_node_ids' => []],
                ]], 'node-network', $requestId);
    }
}
