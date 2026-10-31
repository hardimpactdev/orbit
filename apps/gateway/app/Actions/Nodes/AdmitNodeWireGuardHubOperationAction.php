<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodeRolePlacement;
use App\Domain\Nodes\NodeWireGuardInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

/** Records the exact standalone hub. Native steps own fresh rendering and publication. */
final readonly class AdmitNodeWireGuardHubOperationAction
{
    public function __construct(private StartOperationAction $operations, private NodeWireGuardInput $input) {}

    public function execute(Node $node, ?string $requestId = null): Operation
    {
        if (! app(ManagedNodeEligibility::class)->allows($node) || ! app(NodeRolePlacement::class)->allows($node)
            || ! $node->roles()->where('role', RoleName::Vpn)->where('status', LifecycleStatus::Active)->exists()) {
            throw new ResourceOperationException('node.network_unsupported', 'Hub convergence requires its exact active managed VPN Node.', 422);
        }
        $snapshot = $this->input->capture($node);
        if ($snapshot['hub_id'] !== $node->id) {
            throw new ResourceOperationException('node.network_unsupported', 'Only the current VPN owner can publish the hub.', 422);
        }

        return $this->operations->execute('node.wireguard_hub_converge', 'node', $node->id, $node->id,
            ['_wireguard' => $snapshot, 'participant_node_ids' => [$node->id],
                'configuration' => ['$secret' => 'node.vpn_configuration', 'id' => $node->id],
                'private_bindings' => ['configuration' => ['owner_node_id' => $node->id,
                    'read_node_ids' => [$node->id], 'write_node_ids' => []]]], 'operator', $requestId);
    }
}
