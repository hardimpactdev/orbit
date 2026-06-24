<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\Process;
use App\Services\Nodes\Roles\NodeRoleAssignments;

final readonly class AnalyticsDatabaseResolver
{
    public function __construct(
        private NodeRoleAssignments $roles,
    ) {}

    public function usablePostgresNode(int $nodeId): ?Node
    {
        return $this->usableDatabaseNode($nodeId, 'postgres');
    }

    public function usableClickHouseNode(int $nodeId): ?Node
    {
        return $this->usableDatabaseNode($nodeId, 'clickhouse');
    }

    private function usableDatabaseNode(int $nodeId, string $service): ?Node
    {
        $node = Node::query()->find($nodeId);

        if (! $node instanceof Node) {
            return null;
        }

        if (! $node->isActive()) {
            return null;
        }

        if (! $this->roles->nodeHasActiveRole($node, NodeRoleName::Database->value)) {
            return null;
        }

        $hasProcess = Process::query()
            ->ownedBy($node)
            ->withRuntimeService($service)
            ->exists();

        return $hasProcess ? $node : null;
    }
}
