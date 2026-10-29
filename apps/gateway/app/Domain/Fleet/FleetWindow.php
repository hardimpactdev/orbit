<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationRunConstraint;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Carbon\CarbonInterface;

/** A mixed-role Node uses its most restrictive window, including across DST. */
final readonly class FleetWindow implements OperationRunConstraint
{
    public const array Constraints = ['fleet.window' => ['roles' => 'current']];

    public function name(): string
    {
        return 'fleet.window';
    }

    public function assertAllowed(Operation $operation, array $constraint): void
    {
        if ($constraint !== self::Constraints['fleet.window'] || $operation->node_id === null
            || ! $this->allows(Node::query()->with('roles')->findOrFail($operation->node_id))) {
            throw new ResourceOperationException('operation.run_constraint_failed', 'Fleet effects on this Node require 03:00–06:00 Europe/Amsterdam.', 409);
        }
    }

    public function allows(Node $node, ?CarbonInterface $at = null): bool
    {
        $devOnly = $this->daytime($node);
        $hour = ($at ?? now())->copy()->setTimezone('Europe/Amsterdam')->hour;

        return $devOnly || ($hour >= 3 && $hour < 6);
    }

    public function daytime(Node $node): bool
    {
        $roles = $node->roles;

        return $roles->isNotEmpty() && $roles->every(static fn ($assignment): bool => $assignment->role === RoleName::AppDev);
    }
}
