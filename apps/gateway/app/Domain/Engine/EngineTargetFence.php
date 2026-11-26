<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/**
 * One rule for a target a receipt-bound engine run fences (docs/reference/gateway-engine.md#processstart-and-processstop): the
 * run binds only while no other open Operation acts on its target, and while it holds the lock no new Operation for the target
 * is admitted, on either engine.
 */
final readonly class EngineTargetFence
{
    /** Inside the bind transaction: no other open Operation may act on the run's target. */
    public static function alone(Operation $operation): void
    {
        $other = Operation::query()->where('target_type', $operation->target_type)->where('target_id', $operation->target_id)
            ->whereKeyNot($operation->id)->whereIn('status', OperationStatus::openValues())->lockForUpdate()->exists();
        if ($other) {
            throw new ResourceOperationException('operation.target_busy', 'Another Operation acts on this target.', 409);
        }
    }

    /** At admission: refuses while an open run holds the target's lock. */
    public static function free(string $targetType, int $targetId): void
    {
        $held = Operation::query()->where('lock_key', "{$targetType}:{$targetId}")->whereIn('status', OperationStatus::openValues())->exists();
        if ($held) {
            throw new ResourceOperationException('operation.target_busy', 'Another Operation owns this target.', 409);
        }
    }
}
