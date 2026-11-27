<?php

declare(strict_types=1);

namespace App\Jobs\Tasks;

use App\Actions\Tasks\StartTaskProvisionAction;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskLifecycle;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Starts the create of a group's task copy once more after its first create failed and its copy is gone
 * (docs/reference/tasks.md#a-failed-create): the normal start of the group, as its first caller, with the tasks extension on. A group that someone started, cancelled
 * or moved meanwhile, or that records another failure, is left alone. A start that refuses is the second failure: it asks for
 * assistance with both, and nothing is left to remove.
 */
final class RetryTaskCopyCreate implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $groupId, public int $operationId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Tasks->value)->afterCommit();
    }

    public function handle(StartTaskProvisionAction $start): void
    {
        [$group] = $this->owed() ?? [null];
        if ($group === null) {
            return;
        }
        try {
            // The start reads the default and its Node before its own transaction, so it runs outside any lock.
            $start->execute($group, Operation::query()->find($this->operationId)->triggered_by ?? 'tasks', null);
        } catch (ResourceOperationException $refusal) {
            DB::transaction(function () use ($refusal): void {
                [$group, $first] = $this->owed(lock: true) ?? [null, null];
                if ($group === null || $first === null) {
                    return;
                }
                $group->update(['copy_create_failure' => null]);
                TaskLifecycle::askAfterRetry($group, $first, "a new create, was refused with {$refusal->errorCode}");
            });
        }
    }

    /**
     * The group and the failure this retry follows, while the group is `todo`, holds no copy and still records that failure.
     *
     * @return array{Task, array{operation_id: int, failed_step: string, error_code: string}}|null
     */
    private function owed(bool $lock = false): ?array
    {
        $query = Task::topLevel();
        $group = ($lock ? $query->lockForUpdate() : $query)->find($this->groupId);
        $failure = $group?->copy_create_failure;

        return $group === null || $failure === null || $group->status !== TaskGroupStatus::Todo || $group->taskable_id !== null
            || $failure['operation_id'] !== $this->operationId || self::cancelled($group, $failure['operation_id']) ? null : [$group, $failure];
    }

    /** A `task:cancel` that destroyed the failed copy ends the group, whichever worker recorded the destroy (TaskEnd::sweep). */
    private static function cancelled(Task $group, int $create): bool
    {
        $copy = Operation::query()->whereKey($create)->value('target_id');

        return $copy !== null && Operation::query()->where('kind', 'tasks.cancel')->where('target_type', 'task_group')->where('target_id', $group->id)
            ->where('input->instance_id', $copy)->exists();
    }
}
