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

final readonly class TaskVmLifecycleCompletion implements OperationCompletion
{
    public function __construct(private FailTaskVmAction $fail) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $vm = TaskVm::query()->findOrFail(ValidatedData::integer($operation->input['task_vm_id']));
        if ($outcome->status === OperationStatus::Succeeded) {
            $from = match ($operation->kind) {
                'task_vm.resume' => [TaskVmState::Parked],
                'task_vm.park' => [TaskVmState::Ready, TaskVmState::Parked],
                'task_vm.destroy' => [TaskVmState::Destroying],
                default => [TaskVmState::Provisioning],
            };
            $values = match ($operation->kind) {
                'task_vm.park' => ['state' => TaskVmState::Parked, 'stop_confirmed_at' => now(), ...TaskVm::ForgetTopology],
                'task_vm.destroy' => ['state' => TaskVmState::Destroyed, 'destroyed_at' => now(), 'pi_token' => null, 'model_key' => null, 'worker_stop_required' => false],
                default => ['state' => TaskVmState::Ready, 'ready_at' => now(), ...TaskVm::ForgetTopology],
            };
            TaskVm::query()->whereKey($vm->id)->whereIn('state', $from)->update([...$values, 'error_code' => null, 'error_message' => null]);
        } elseif ($outcome->status === OperationStatus::Failed) {
            $code = $outcome->errorCode ?? 'task_vm.job_failed';
            if (in_array($operation->kind, ['task_vm.park', 'task_vm.destroy'], true)) {
                $vm->update(['error_code' => $code, 'error_message' => 'The task VM effect failed.', 'stop_confirmed_at' => null]);
            } else {
                $this->fail->execute($vm, [$operation->kind === 'task_vm.resume' ? TaskVmState::Parked : TaskVmState::Provisioning], $code, 'The task VM effect failed.', 'The task VM effect failed.');
            }
        }
    }
}
