<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Illuminate\Database\Eloquent\Builder;

/** The VPN role serves private DNS; a separate Gateway only consumes it. */
final readonly class PrivateDnsPublicationNodes
{
    /** @return Builder<Node> */
    public static function query(): Builder
    {
        return Node::query()->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static fn (Builder $roles): Builder => $roles
                ->where('role', RoleName::Vpn)->where('status', LifecycleStatus::Active))
            ->orderBy('id');
    }
}
