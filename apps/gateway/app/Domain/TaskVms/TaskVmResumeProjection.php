<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\TaskVms\FailTaskVmAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
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
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $result = ValidatedData::object($data['result'] ?? []);
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($terminal) {
                return $projected;
            }
            $vm = TaskVm::query()->lockForUpdate()->find($operation->target_id);
            if ($vm?->state === TaskVmState::Parked) {
                if ($projected->status === OperationStatus::Succeeded) {
                    $vm->update(['state' => TaskVmState::Ready, 'ready_at' => now(), 'error_code' => null, 'error_message' => null, ...TaskVm::ForgetTopology]);
                } elseif ($projected->status === OperationStatus::Failed) {
                    $this->fail->execute($vm, [TaskVmState::Parked], ValidatedData::string($data['error_code']), 'The task VM resume could not be confirmed.', 'The task VM resume could not be confirmed.');
                }
            }

            return $projected;
        });
    }
}
