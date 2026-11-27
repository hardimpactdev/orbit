<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Processes\ProcessControlEngineFlow;
use App\Domain\Schedules\ScheduleEnableEngineFlow;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
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
        if ($query->lockForUpdate()->exists() || (in_array($operation->kind, ProcessControlEngineFlow::Kinds, true) && self::parentRemoving($operation->target_id))) {
            throw new ResourceOperationException('operation.target_busy', 'Another Operation acts on this target.', 409);
        }
    }

    /** Whether an open removal of the Process's Instance, Node, Node role or database server owns it and will delete it. */
    private static function parentRemoving(int $processId): bool
    {
        $process = Process::query()->find($processId);
        if ($process === null) {
            return true;
        }
        $open = static fn (): Builder => Operation::query()->whereIn('status', OperationStatus::openValues());
        if (Instance::isMorphType($process->owner_type)) {
            return $open()->where('kind', 'instance.destroy')->where('target_type', 'instance')->where('target_id', $process->owner_id)->exists();
        }

        return $open()->whereIn('kind', ['node.remove', 'node.role_remove'])->where('target_type', 'node')->where('target_id', $process->owner_id)->exists()
            || $open()->where('kind', 'database_server.destroy')->where('input->process_id', $processId)->exists();
    }

    /** At admission, before anything changes: refuses while an open run holds the resource's lock. */
    public static function free(string $lockKey): void
    {
        if (Operation::query()->where('lock_key', $lockKey)->whereIn('status', OperationStatus::openValues())->exists()) {
            throw new ResourceOperationException('operation.target_busy', 'Another Operation owns this target.', 409);
        }
    }

    /**
     * At a parent removal's admission (an Instance, a Node, a Node role, a database server), before teardown starts: refuses
     * while an engine run holds any Process the removal would delete, so no Process row disappears under an outstanding order.
     *
     * @param  iterable<mixed>  $processIds
     */
    public static function processesFree(iterable $processIds): void
    {
        $keys = [];
        foreach ($processIds as $id) {
            $keys[] = OperationStore::lockKey('process', ValidatedData::integer($id));
        }
        if ($keys !== [] && Operation::query()->whereIn('lock_key', $keys)->whereIn('status', OperationStatus::openValues())->exists()) {
            throw new ResourceOperationException('operation.target_busy', 'An engine run holds a Process this removal would delete.', 409);
        }
    }

    public static function scheduleKey(string $id): string
    {
        return 'schedule:'.$id;
    }
}
