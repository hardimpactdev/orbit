<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;

/** Unit installation precedes the active transition during Gateway bootstrap. */
final class GatewayCommandNode
{
    public static function find(bool $converging = false): ?Node
    {
        $statuses = $converging ? [LifecycleStatus::Active, LifecycleStatus::Provisioning] : [LifecycleStatus::Active];

        return Node::query()->whereIn('status', $statuses)
            ->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->whereIn('status', $statuses))
            ->first();
    }
}
