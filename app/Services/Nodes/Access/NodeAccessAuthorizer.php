<?php

declare(strict_types=1);

namespace App\Services\Nodes\Access;

use App\Models\Node;
use App\Models\NodeAccess;
use App\Services\Nodes\Roles\NodeRoleAssignments;

class NodeAccessAuthorizer
{
    public function __construct(
        private readonly NodePermissionRegistry $registry,
        private readonly NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function allows(Node $consumer, Node $serving, string $permission): bool
    {
        if ($this->nodeRoleAssignments->nodeIsGateway($consumer)) {
            return true;
        }

        $gateway = $this->resolveGateway();

        if ($gateway !== null) {
            $gatewayGrant = NodeAccess::query()
                ->where('consumer_node_id', $consumer->id)
                ->where('serving_node_id', $gateway->id)
                ->first();

            if ($gatewayGrant !== null && in_array('*', $gatewayGrant->permissions ?? ['*'], true)) {
                return true;
            }
        }

        $directGrant = NodeAccess::query()
            ->where('consumer_node_id', $consumer->id)
            ->where('serving_node_id', $serving->id)
            ->first();

        if ($directGrant !== null) {
            return $this->registry->allows($directGrant->permissions ?? ['*'], $permission);
        }

        return false;
    }

    private function resolveGateway(): ?Node
    {
        $gateway = $this->nodeRoleAssignments->activeGatewayNodeQuery()->first();

        return $gateway instanceof Node ? $gateway : null;
    }
}
