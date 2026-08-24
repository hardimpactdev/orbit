<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\OperationRun;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Collection;

final readonly class FleetUpdateTargetSelector
{
    public function __construct(
        private NodeRoleAssignments $roles,
    ) {}

    /**
     * @return Collection<int, Node>
     */
    public function workloadNodes(OperationRun $operationRun): Collection
    {
        return $this->eligibleNonGatewayNodes();
    }

    /**
     * @return Collection<int, Node>
     *
     * @mago-expect analysis:invalid-argument
     */
    private function eligibleNonGatewayNodes(): Collection
    {
        $gatewayIds = $this->roles->activeNodeIdsForRole(NodeRoleName::Gateway->value);
        $query = Node::query()->where('status', NodeStatus::Active->value);

        if ($gatewayIds !== []) {
            $query->whereNotIn('id', $gatewayIds);
        }

        /** @var Collection<int, Node> $selected */
        $selected = $query
            ->with('roleAssignments')
            ->orderBy('name')
            ->get()
            ->filter(static fn (Node $node): bool => $node->isFleetUpdateEligible())
            ->unique('id')
            ->keyBy('id')
            ->sortBy('name')
            ->values();

        return $selected;
    }

    /**
     * @return Collection<int, Node>
     */
    public function activeNonGatewayRoleNodes(): Collection
    {
        $gatewayIds = $this->roles->activeNodeIdsForRole(NodeRoleName::Gateway->value);

        /** @var Collection<int, Node> $nodes */
        $nodes = Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $this->roles->activeAssignedNodeIds())
            ->when($gatewayIds !== [], fn ($query) => $query->whereNotIn('id', $gatewayIds))
            ->with('roleAssignments')
            ->orderBy('name')
            ->get();

        return $nodes;
    }

    public function gatewayNode(): ?Node
    {
        $gatewayIds = $this->roles->activeNodeIdsForRole(NodeRoleName::Gateway->value);

        if ($gatewayIds === []) {
            return null;
        }

        /** @var Node|null $node */
        $node = Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $gatewayIds)
            ->with('roleAssignments')
            ->orderBy('name')
            ->first();

        return $node;
    }
}
