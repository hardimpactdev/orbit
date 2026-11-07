<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\TaskVms\TaskVmSweepWorkflowInput;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** One immutable ownership capture of the UpCloud servers and one native root, on the Gateway Node; no host or provider calls. */
final readonly class StartTaskVmSweepOperationAction
{
    public function __construct(private StartOperationAction $operations, private TaskVmSweepWorkflowInput $inputs) {}

    public function execute(int $nodeId): Operation
    {
        return DB::transaction(function () use ($nodeId): Operation {
            $node = Node::query()->lockForUpdate()->findOrFail($nodeId);
            $existing = Operation::query()->where('kind', 'task_vm.sweep_host')->where('target_type', 'task_vm_sweep')->where('target_id', $nodeId)
                ->whereNotIn('status', OperationStatus::terminalValues())->first();
            if ($existing !== null) {
                return $existing;
            }

            return $this->operations->execute('task_vm.sweep_host', 'task_vm_sweep', $nodeId, $nodeId,
                $this->inputs->capture($node), 'task-vm', null, lockKey: OperationStore::lockKey('task_vm_sweep', $nodeId));
        });
    }
}
