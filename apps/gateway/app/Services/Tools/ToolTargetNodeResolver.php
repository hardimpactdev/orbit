<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;

final readonly class ToolTargetNodeResolver
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private ToolCatalog $catalog,
        private ToolAppNodeResolver $appNodes,
    ) {}

    public function resolveFilter(?string $node, ?string $app, ?string $tool = null): ?Node
    {
        if ($node !== null) {
            return $this->resolveNode($node, $tool);
        }

        if ($app !== null) {
            return $this->appNodes->resolve($app);
        }

        return null;
    }

    public function resolveStored(?string $node, ?string $app): ?Node
    {
        if ($node !== null) {
            return Node::query()
                ->where('name', $node)
                ->where('status', NodeStatus::Active->value)
                ->first();
        }

        return $this->appNodes->resolve($app);
    }

    private function resolveNode(string $node, ?string $tool): ?Node
    {
        $query = Node::query()
            ->where('name', $node)
            ->where('status', NodeStatus::Active->value);

        if ($tool !== null && $this->catalog->gatewayLocal($tool)) {
            $query->whereIn('id', $this->gatewayNodeIds());
        } else {
            $query->whereNotIn('id', $this->gatewayNodeIds());
        }

        $resolved = $query->first();

        return $resolved instanceof Node && ($tool === null || $this->catalog->supportsNode($tool, $resolved))
            ? $resolved
            : null;
    }

    /** @return list<int> */
    private function gatewayNodeIds(): array
    {
        $ids = $this->nodeRoleAssignments
            ->activeGatewayNodeQuery()
            ->pluck('id')
            ->map(fn (mixed $nodeId): int => (int) $nodeId)
            ->values()
            ->all();

        return array_values($ids);
    }
}
