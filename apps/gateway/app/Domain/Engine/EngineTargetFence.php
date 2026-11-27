<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\ProcessControlEngineFlow;
use App\Domain\Schedules\ScheduleEnableEngineFlow;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * One rule for the resource a receipt-bound engine run fences (docs/reference/gateway-engine.md#processstart-and-processstop):
 * the run binds only while no other open Operation acts on its resource, and while it holds the resource's lock no new
 * Operation for that resource is admitted, on either engine. A Process is its Operation target; a Schedule is its UUID, so
 * the other Schedules of the same owner stay free.
 */
final readonly class EngineTargetFence
{
    /**
     * Inside the bind transaction of a receipt-bound run: a Process or Schedule run binds only while no other open Operation
     * acts on the same Process, or on the same Schedule UUID. Other kinds keep their own rules.
     */
    public static function alone(Operation $operation): void
    {
        $query = Operation::query()->where('target_type', $operation->target_type)->where('target_id', $operation->target_id)
            ->whereKeyNot($operation->id)->whereIn('status', OperationStatus::openValues());
        if ($operation->kind === ScheduleEnableEngineFlow::Kind) {
            $query->where('kind', 'like', 'schedule.%')->where('input->id', ValidatedData::string($operation->input['id'] ?? null));
        } elseif (! in_array($operation->kind, ProcessControlEngineFlow::Kinds, true)) {
            return;
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
