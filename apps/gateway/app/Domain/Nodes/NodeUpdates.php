<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Nodes\NodeUpdatingData;
use App\Domain\Shared\LifecycleStatus;
use App\Models\GatewayRelease;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Carbon;

/**
 * Which Nodes the Gateway is updating now ([Nodes being updated](/reference/gateway-recovery#nodes-being-updated)):
 * the Gateway Node while a release record is `running`. A fleet rollout visit is followed in its Operation.
 */
final readonly class NodeUpdates
{
    public function forNode(Node $node): ?NodeUpdatingData
    {
        return $this->forNodes([$node])[$node->id] ?? null;
    }

    /**
     * @param  iterable<Node>  $nodes
     * @return array<int, NodeUpdatingData> keyed by Node id, only for the Nodes that are updating
     */
    public function forNodes(iterable $nodes): array
    {
        $gateways = [];

        foreach ($nodes as $node) {
            if ($this->isGateway($node)) {
                $gateways[] = $node->id;
            }
        }

        $updates = [];
        $release = $gateways === [] ? null : $this->runningRelease();

        if ($release instanceof GatewayRelease) {
            foreach ($gateways as $id) {
                $updates[$id] = new NodeUpdatingData(
                    kind: NodeUpdateKind::GatewayRelease,
                    since: ($release->created_at ?? Carbon::now())->toIso8601String(),
                    release: $release->id,
                );
            }
        }

        return $updates;
    }

    private function runningRelease(): ?GatewayRelease
    {
        return GatewayRelease::query()
            ->where('outcome', GatewayRelease::Running)
            ->latest('id')
            ->first(['id', 'created_at']);
    }

    private function isGateway(Node $node): bool
    {
        if ($node->status !== LifecycleStatus::Active) {
            return false;
        }

        return $node->roles->contains(
            static fn (NodeRole $role): bool => $role->role === RoleName::Gateway && $role->status === LifecycleStatus::Active,
        );
    }
}
