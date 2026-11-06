<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\TaskVms\FailTaskVmAction;
use App\Actions\TaskVms\WakeTaskVmAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Native results change VM metadata only; no provider or guest calls. */
final readonly class TaskVmResumeProjection implements DirectOperationProjection
{
    public function __construct(private TaskVmResumeIntent $intent, private FailTaskVmAction $fail) {}

    public function input(Operation $operation): array
    {
        return $this->intent->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        // No result step: a succeeded run's end check proved the agent and the Pi answer over the recorded host key pin.
        if (in_array($data['status'] ?? null, ['failed', 'interrupted'], true)) {
            $data['error_code'] = 'task_vm.resume_failed';
        }

        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $terminal = $operation->status->terminal();
            if (! $terminal && ($data['status'] ?? null) === 'succeeded') {
                $this->intent->owned($operation, true);
            }
            $failed = in_array($data['status'] ?? null, ['failed', 'interrupted'], true);
            $projected = app(DirectOperation::class)->project($operation, $data, $failed ? ['error_code' => $data['error_code']] : []);
            if ($terminal) {
                return $projected;
            }
            $vm = TaskVm::query()->lockForUpdate()->find($operation->target_id);
            if ($vm !== null && $vm->state->value === ($operation->input['admitted_state'] ?? null)) {
                if ($projected->status === OperationStatus::Succeeded && $vm->state !== TaskVmState::Ready) {
                    $vm->update(['state' => TaskVmState::Ready, 'ready_at' => now(), 'error_code' => null, 'error_message' => null, ...TaskVm::ForgetTopology]);
                    Task::query()->whereKey($vm->group_id)->where('capacity_wait_reason', WakeTaskVmAction::Resuming)->update(['capacity_wait_reason' => null]);
                } elseif ($projected->status === OperationStatus::Failed && $vm->state === TaskVmState::Parked) {
                    $this->fail->execute($vm, [$vm->state], ValidatedData::string($data['error_code']), 'The task VM resume could not be confirmed.', 'The task VM resume could not be confirmed.');
                }
            }

            return $projected;
        });
    }
}
