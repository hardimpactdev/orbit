<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Illuminate\Database\Eloquent\Builder;

/**
 * One rule for the resource a receipt-bound engine run fences (docs/reference/gateway-engine.md#processstart-and-processstop):
 * the run binds only while no other open Operation acts on its resource, and while it holds the resource's lock no new
 * Operation for that resource is admitted, on either engine. A Process is its Operation target; a Schedule is its UUID, so
 * the other Schedules of the same owner stay free.
 */
final readonly class EngineTargetFence
{
    /**
     * Inside the bind transaction: no other open Operation may act on the resource.
     *
     * @param  (callable(Builder<Operation>): mixed)|null  $resource  Narrows the Operations of the target to the resource's own.
     */
    public static function alone(Operation $operation, ?callable $resource = null): void
    {
        $query = Operation::query()->where('target_type', $operation->target_type)->where('target_id', $operation->target_id)
            ->whereKeyNot($operation->id)->whereIn('status', OperationStatus::openValues());
        if ($resource !== null) {
            $resource($query);
        }
        if ($query->lockForUpdate()->exists()) {
            throw new ResourceOperationException('operation.target_busy', 'Another Operation acts on this target.', 409);
        }
    }

    /** At admission, before anything changes: refuses while an open run holds the resource's lock. */
    public static function free(string $lockKey): void
    {
        if (Operation::query()->where('lock_key', $lockKey)->whereIn('status', OperationStatus::openValues())->exists()) {
            throw new ResourceOperationException('operation.target_busy', 'Another Operation owns this target.', 409);
        }
    }

    public static function scheduleKey(string $id): string
    {
        return 'schedule:'.$id;
    }
}
