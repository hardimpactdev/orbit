<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmSlotReleaseIntent;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Records the release of one slot and starts it as one Operation. The slot becomes `destroying`, so no claim takes it.
 * A repeated admission returns the open Operation. No host call: Swamp deletes the VM and its seed clone.
 */
final readonly class StartTaskVmSlotReleaseAction
{
    public function __construct(private StartOperationAction $start, private TaskVmSlotReleaseIntent $intent) {}

    public function execute(int $taskVmId): ?Operation
    {
        return DB::transaction(function () use ($taskVmId): ?Operation {
            $vm = TaskVm::query()->lockForUpdate()->find($taskVmId);
            if ($vm === null || $vm->slot === null || in_array($vm->state, [TaskVmState::Free, TaskVmState::Destroyed], true)) {
                return null;
            }
            $existing = Operation::query()->where('target_type', 'task_vm')->where('target_id', $vm->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->first();
            if ($existing !== null) {
                return $existing->kind === 'task_vm.slot_release' ? $existing
                    : throw new ResourceOperationException('operation.target_busy', 'The earlier slot Operation is still settling.', 409, details: ['operation_id' => $existing->id]);
            }
            $held = Operation::query()->where('lock_key', OperationStore::lockKey('task_vm', $vm->id))->value('id');
            if ($held !== null) {
                throw new ResourceOperationException('operation.target_busy', 'Another Operation owns this target.', 409, details: ['operation_id' => $held]);
            }
            $input = $this->intent->capture($vm);
            $operation = $this->start->execute('task_vm.slot_release', 'task_vm', $vm->id, ValidatedData::integer($input['node_id']), $input, 'task-vm', null, lockKey: OperationStore::lockKey('task_vm', $vm->id));
            $vm->update(['state' => TaskVmState::Destroying]);

            return $operation;
        });
    }

    /**
     * Starts the release once the caller's transaction commits, for a task that just ended. A refused or failed start is
     * reported and leaves the slot as the caller recorded it (`destroying` or `failed`); the host sweep admits it again.
     */
    public function afterCommit(int $taskVmId): void
    {
        DB::afterCommit(function () use ($taskVmId): void {
            try {
                $this->execute($taskVmId);
            } catch (Throwable $error) {
                report($error);
            }
        });
    }
}
