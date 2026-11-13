<?php

declare(strict_types=1);

namespace App\Jobs\Tasks;

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Destroys the copy of a task group whose start failed (docs/cli/task/start.mmd), after the create's projection committed. It admits
 * the normal `instance.destroy` with force. A copy of a group that still runs, or one that an Operation still holds, is left alone. When
 * the destroy fails, the failed group keeps owning the copy; `orbit instance:destroy <id> --yes --force` is the retry.
 */
final class DestroyTaskCopy implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public int $instanceId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Tasks->value)->afterCommit();
    }

    public function handle(StartInstanceDestroyAction $destroy): void
    {
        $instance = Instance::query()->find($this->instanceId);
        if ($instance === null || $instance->task_workspace_owner_id === null || ! $instance->runtime()->exists()
            || Task::topLevel()->whereKey($instance->task_workspace_owner_id)->whereIn('status', TaskGroupStatus::active())->exists()
            || Operation::query()->where('target_type', 'instance')->where('target_id', $instance->id)->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
            return;
        }
        $destroy->execute($instance, true, 'tasks');
    }
}
