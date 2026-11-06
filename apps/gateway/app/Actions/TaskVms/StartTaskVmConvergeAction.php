<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmConvergeIntent;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;

/** Admit one converge of a running task VM; Swamp runs the Ansible phases. */
final readonly class StartTaskVmConvergeAction
{
    public function __construct(private StartOperationAction $start, private TaskVmConvergeIntent $intent) {}

    /** @param array{subnet:string,interface:string}|null $nested */
    public function execute(int $taskVmId, ?array $nested = null): Operation
    {
        return DB::transaction(function () use ($taskVmId, $nested): Operation {
            $vm = TaskVm::query()->lockForUpdate()->find($taskVmId)
                ?? throw new ResourceOperationException('task_vm.converge_refused', 'The task VM does not exist.', 404);
            $existing = Operation::query()->where('target_type', 'task_vm')->where('target_id', $vm->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->first();
            if ($existing !== null) {
                throw new ResourceOperationException('operation.target_busy', 'An earlier Operation of this VM is still running.', 409, details: ['operation_id' => $existing->id]);
            }
            $intent = $this->intent->capture($vm, $nested);

            return $this->start->execute('task_vm.converge', 'task_vm', $vm->id, $vm->node_id, ['intent' => $intent], 'task-vm', null);
        });
    }
}
