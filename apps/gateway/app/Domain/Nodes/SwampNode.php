<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;

/**
 * The Swamp Node: the one Node that holds the `swamp` role, where serve and its workers run. Every admission that names
 * the Node Swamp executes from looks it up here, by role. Nothing in the Gateway knows its id.
 *
 * A role that is being added counts, because the first run of a new Gateway admits the swamp role itself.
 */
final class SwampNode
{
    public static function idOrNull(): ?int
    {
        $id = NodeRole::query()->where('role', RoleName::Swamp)
            ->whereIn('status', [LifecycleStatus::Active, LifecycleStatus::Provisioning])->value('node_id');

        return is_int($id) ? $id : null;
    }

    public static function id(): int
    {
        return self::idOrNull() ?? throw new ResourceOperationException('swamp.node_missing', 'No Node holds the swamp role.', 409);
    }

    public static function node(): Node
    {
        return Node::query()->findOrFail(self::id());
    }
}
