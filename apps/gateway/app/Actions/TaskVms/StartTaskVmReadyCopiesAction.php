<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmReadyCopiesIntent;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;

/** Admits the save of the ready copies of one running task VM as one Operation; an open one is returned instead of a second. */
final readonly class StartTaskVmReadyCopiesAction
{
    public function __construct(private StartOperationAction $start, private TaskVmReadyCopiesIntent $intent) {}

    public function execute(int $taskVmId): Operation
    {
        return DB::transaction(function () use ($taskVmId): Operation {
            $vm = TaskVm::query()->lockForUpdate()->findOrFail($taskVmId);
            $existing = Operation::query()->where('target_type', 'task_vm')->where('target_id', $vm->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->first();
            if ($existing !== null) {
                return $existing->kind === 'task_vm.ready_copies' ? $existing
                    : throw new ResourceOperationException('operation.target_busy', 'The earlier VM Operation is still settling.', 409, details: ['operation_id' => $existing->id]);
            }
            $input = $this->intent->capture($vm);

            return $this->start->execute('task_vm.ready_copies', 'task_vm', $vm->id, $vm->host_node_id, $input, 'task-vm', null);
        });
    }
}
