<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmDestroyIntent;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Admit one native root; an earlier nonterminal VM Operation must settle first. */
final readonly class StartTaskVmDestroyAction
{
    public function __construct(private StartOperationAction $start, private TaskVmDestroyIntent $intent) {}

    public function execute(int $taskVmId): ?Operation
    {
        return DB::transaction(function () use ($taskVmId): ?Operation {
            $vm = TaskVm::query()->lockForUpdate()->find($taskVmId);
            if ($vm === null || $vm->state === TaskVmState::Destroyed) {
                return null;
            }
            $existing = Operation::query()->where('target_type', 'task_vm')->where('target_id', $vm->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->first();
            if ($existing !== null) {
                return $existing->kind === 'task_vm.destroy' ? $existing
                    : throw new ResourceOperationException('operation.target_busy', 'The earlier VM Operation is still settling.', 409, details: ['operation_id' => $existing->id]);
            }
            $input = $this->intent->capture($vm);
            $operation = $this->start->execute('task_vm.destroy', 'task_vm', $vm->id, ValidatedData::integer($input['node_id']), $input, 'task-vm', null, lockKey: OperationStore::lockKey('task_vm', $vm->id));
            $vm->update(['state' => TaskVmState::Destroying]);

            return $operation;
        });
    }
}
