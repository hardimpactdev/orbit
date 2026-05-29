<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Nodes\Roles\NodeRoleAssignments;

final readonly class WebSocketRedisResolver
{
    public function __construct(
        private NodeRoleAssignments $roles,
    ) {}

    public function usableRedisNode(int $nodeId): ?Node
    {
        $node = Node::query()->find($nodeId);

        if (! $node instanceof Node) {
            return null;
        }

        if ($node->status !== Node::STATUS_ACTIVE) {
            return null;
        }

        if (! $this->roles->nodeHasActiveRole($node, NodeRoleName::Database->value)) {
            return null;
        }

        $hasRedis = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'redis')
            ->whereIn('expected_state', ['installed', 'running'])
            ->exists();

        return $hasRedis ? $node : null;
    }
}
