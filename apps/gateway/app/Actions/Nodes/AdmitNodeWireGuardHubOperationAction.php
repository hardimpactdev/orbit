<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;

/** Records the hub's public peer list as full intent. The hub keeps its own key; the run replaces only its peers. */
final readonly class AdmitNodeWireGuardHubOperationAction
{
    public function __construct(private StartOperationAction $operations) {}

    public function execute(Node $node, ?string $requestId = null): Operation
    {
        // Swamp reaches the hub over SSH from 209, so no Node worker placement applies.
        if (! app(ManagedNodeEligibility::class)->allows($node)
            || ! $node->roles()->where('role', RoleName::Vpn)->where('status', LifecycleStatus::Active)->exists()) {
            throw new ResourceOperationException('node.network_unsupported', 'Hub convergence requires its exact active managed VPN Node.', 422);
        }
        $peers = Node::query()->whereKeyNot($node->id)->whereNotNull('wireguard_public_key')->whereNotNull('wireguard_ip')
            ->where('status', '!=', LifecycleStatus::Removing)->orderBy('id')->get()
            ->map(static fn (Node $peer): array => ['name' => $peer->name, 'public_key' => $peer->wireguard_public_key, 'address' => $peer->wireguard_ip])
            ->all();
        $intent = ['hub_id' => $node->id, 'ssh' => app(SwampSshExecutionInput::class)->forNode($node),
            'action_json' => json_encode(['hub_id' => $node->id, 'peers' => $peers], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)];

        return $this->operations->execute('node.wireguard_hub_converge', 'node', $node->id, $node->id,
            ['intent' => $intent, 'participant_node_ids' => [$node->id]], 'operator', $requestId);
    }
}
