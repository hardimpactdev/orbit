<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Nodes\RoleName;
use App\Models\Node;

/**
 * Every app-dev Node hosts Instance containers. CI-only proof hosts are selected separately by InstanceHostIntent.
 * The nested fleet identity below still selects its host-specific mounts and Incus settings.
 */
final readonly class ContainerNodes
{
    public function includes(Node $node): bool
    {
        return $node->roles()->where('role', RoleName::AppDev)->exists();
    }

    /** Whether the Node is the `app-dev` Node of this nested Gateway's Instance fleet: `nested-app-dev`, a third level below the host. */
    public static function fleetNode(Node $node): bool
    {
        return InstanceFleetGateway::holds() && $node->public_ssh_host === NestedFleetLayout::Layout['app-dev']['address'];
    }
}
