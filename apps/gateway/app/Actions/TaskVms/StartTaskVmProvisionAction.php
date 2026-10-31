<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmEnrollmentIntent;
use App\Domain\TaskVms\TaskVmProvisionIntent;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Record one allocation intent and start its native DAG after the transaction commits. */
final readonly class StartTaskVmProvisionAction
{
    public function __construct(private StartOperationAction $start, private TaskVmProvisionIntent $intent, private TaskVmEnrollmentIntent $enrollment) {}

    public function execute(int $taskVmId): ?Operation
    {
        return $this->enrollment->admit(fn () => DB::transaction(function () use ($taskVmId): ?Operation {
            $vm = TaskVm::query()->lockForUpdate()->find($taskVmId);
            if ($vm === null || $vm->state !== TaskVmState::Provisioning) {
                return null;
            }
            $existing = Operation::query()->where('target_type', 'task_vm')->where('target_id', $vm->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->first();
            if ($existing !== null) {
                return $existing->kind === 'task_vm.provision' ? $existing
                    : throw new ResourceOperationException('operation.target_busy', 'The earlier VM Operation is still settling.', 409, details: ['operation_id' => $existing->id]);
            }
            $input = $this->intent->capture($vm);

            return $this->start->execute('task_vm.provision', 'task_vm', $vm->id, ValidatedData::integer($input['node_id']), $input, 'task-vm', null);
        }));
    }
}
