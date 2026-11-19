<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\NodeFact;

/**
 * The app-dev Nodes that host Instance containers: those with the Incus pool `orbit-instances`
 * (docs/reference/development-containers.md#nodes). The Gateway reads no pool. The operator states it for a new host with the Node
 * setting `instance_host`, before its first converge. It is taken as given, without the setting, when the Node is the nested fleet's
 * app-dev Node, whose converge creates a directory pool, holds an Instance runtime, or its agent reports the Instance bridge's health,
 * which only a converged container branch writes. Any other app-dev Node, such as a services Node without Incus, keeps its other roles
 * and gets no container branch. CI-only proof hosts are selected separately by InstanceHostIntent.
 */
final readonly class ContainerNodes
{
    public function includes(Node $node): bool
    {
        if (! $node->roles()->where('role', RoleName::AppDev)->exists()) {
            return false;
        }

        return self::fleetNode($node)
            || app(NodeSettingsNormalizer::class)->fromStored($node->settings)?->instance_host === true
            || InstanceRuntime::query()->where('node_id', $node->id)->exists()
            || self::bridgeHealth($node) !== null;
    }

    /** Whether the Node is the `app-dev` Node of this nested Gateway's Instance fleet: `nested-app-dev`, a third level below the host. */
    public static function fleetNode(Node $node): bool
    {
        return InstanceFleetGateway::holds() && $node->public_ssh_host === NestedFleetLayout::Layout['app-dev']['address'];
    }

    /** The Instance bridge health from the Node's host fact, or null when the agent reports none. */
    private static function bridgeHealth(Node $node): mixed
    {
        $data = NodeFact::query()->where('node_id', $node->id)->where('kind', NodeFactKind::Host)->value('data');

        return is_array($data) ? ($data['instance_bridge'] ?? null) : null;
    }
}
