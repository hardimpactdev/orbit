<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\TaskVms\TaskVmSeedRefreshIntent;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/**
 * Starts the refresh of one task VM host's topology seed as one Operation. The host holds the lock, so one refresh runs
 * at a time; a refresh that is running is returned instead of a second one. No host or provider call: Swamp does the work.
 */
final readonly class StartTaskVmSeedRefreshAction
{
    public function __construct(private StartOperationAction $start, private TaskVmSeedRefreshIntent $intent) {}

    public function execute(Node $host): Operation
    {
        return DB::transaction(function () use ($host): Operation {
            $host = Node::query()->lockForUpdate()->findOrFail($host->id);
            $existing = Operation::query()->where('kind', 'task_vm.seed_refresh')->where('target_type', 'task_vm_host')->where('target_id', $host->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->first();
            if ($existing instanceof Operation) {
                return $existing;
            }

            return $this->start->execute('task_vm.seed_refresh', 'task_vm_host', $host->id, $host->id, $this->intent->capture($host), 'task-vm', null,
                lockKey: OperationStore::lockKey('task_vm_host', $host->id));
        });
    }
}
