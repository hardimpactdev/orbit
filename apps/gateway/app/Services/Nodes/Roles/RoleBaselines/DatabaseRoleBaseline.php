<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Nodes\Roles\NodeRoleRegistry;
use App\Services\Tools\ToolCatalog;
use RuntimeException;

class DatabaseRoleBaseline implements RoleBaseline
{
    use ManagesNodeToolBaseline;

    public function __construct(
        private readonly ?ToolCatalog $toolCatalog = null,
        private readonly ?NodeRoleAssignments $nodeRoleAssignments = null,
        private readonly ?NodeRoleRegistry $nodeRoleRegistry = null,
    ) {}

    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        if ($this->nodeRoleAssignments()->nodeIsGateway($node)) {
            throw new RuntimeException('The database role cannot be assigned to a gateway node.');
        }

        $definition = $this->nodeRoleRegistry()->definition(NodeRoleName::Database->value);

        if (! $this->nodeRoleAssignments()->platformSupported($definition, $node->platform)) {
            throw new RuntimeException('The database role requires an Ubuntu or macOS host.');
        }

        $this->convergeTools($node, ['docker']);
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        $this->removeTools($node, ['docker']);
    }

    protected function toolCatalog(): ToolCatalog
    {
        return $this->toolCatalog ?? app(ToolCatalog::class);
    }

    private function nodeRoleAssignments(): NodeRoleAssignments
    {
        return $this->nodeRoleAssignments ?? app(NodeRoleAssignments::class);
    }

    private function nodeRoleRegistry(): NodeRoleRegistry
    {
        return $this->nodeRoleRegistry ?? app(NodeRoleRegistry::class);
    }
}
