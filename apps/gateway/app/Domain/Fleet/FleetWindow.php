<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Nodes\RoleName;
use App\Models\Node;
use Carbon\CarbonInterface;

/** A mixed-role Node uses its most restrictive window, including across DST. */
final readonly class FleetWindow
{
    public function allows(Node $node, ?CarbonInterface $at = null): bool
    {
        $roles = $node->roles;
        $devOnly = $roles->isNotEmpty() && $roles->every(static fn ($assignment): bool => $assignment->role === RoleName::AppDev);
        $hour = ($at ?? now())->copy()->setTimezone('Europe/Amsterdam')->hour;

        return $devOnly || ($hour >= 3 && $hour < 6);
    }
}
