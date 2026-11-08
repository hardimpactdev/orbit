<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Data\Nodes\NodeRoleAssignmentData;
use App\Models\Node;

final readonly class ListNodeRolesAction
{
    /** @return list<NodeRoleAssignmentData> */
    public function execute(Node $node): array
    {
        return NodeRoleAssignmentData::forNode($node);
    }
}
