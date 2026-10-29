<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\TaskVms\FailTaskVmAction;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;

/** Projects the parent's proven child receipts; a later destroy or failure always wins. */
final readonly class TaskVmRuntimeCompletion implements OperationCompletion
{
    public function __construct(private FailTaskVmAction $fail) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $vm = TaskVm::query()->findOrFail(ValidatedData::integer($operation->input['task_vm_id']));
        if ($outcome->status === OperationStatus::Succeeded) {
            TaskVm::query()->whereKey($vm->id)->where('state', TaskVmState::Provisioning)
                ->update(['state' => TaskVmState::Ready, 'ready_at' => now(), 'error_code' => null, 'error_message' => null]);
        } elseif ($outcome->status === OperationStatus::Failed) {
            $code = $outcome->errorCode ?? 'task_vm.runtime_failed';
            $this->fail->execute($vm, [TaskVmState::Provisioning], $code, 'Task VM runtime preparation failed.', 'Task VM runtime preparation failed.');
        }
    }
}
