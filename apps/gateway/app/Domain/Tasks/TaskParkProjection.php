<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Project one same-root native park result. No provider calls or child Operations. */
final readonly class TaskParkProjection implements DirectOperationProjection
{
    public function __construct(private TaskParkIntent $intent, private TaskExecutionLock $execution) {}

    public function input(Operation $operation): array
    {
        return $this->intent->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = ValidatedData::object($data['result'] ?? []);
        $code = ValidatedData::nullableString($data['error_code'] ?? null);

        return $this->execution->synchronized($operation->target_id, fn (): Operation => DB::transaction(function () use ($operation, $data, $result, $status, $code): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($operation->status->terminal()) {
                return app(DirectOperation::class)->project($operation, $data, $result, 'park');
            }
            $vm = $this->intent->owned($operation);
            if ($status === OperationStatus::Succeeded) {
                TaskParkIntent::requireEligible(Task::topLevel()->findOrFail($operation->target_id));
            }
            $projected = app(DirectOperation::class)->project($operation, $data, $result, 'park');
            if ($status === OperationStatus::Succeeded) {
                $vm->update(['state' => TaskVmState::Parked, 'stop_confirmed_at' => now(), 'error_code' => null, 'error_message' => null, ...TaskVm::ForgetTopology]);
                $group = Task::topLevel()->findOrFail($operation->target_id);
                $group->update(['status' => TaskGroupStatus::WaitingForReview, 'settled_at' => $group->settled_at ?? now()]);
            } elseif ($status === OperationStatus::Failed) {
                TaskVm::query()->whereKey($vm->id)->whereIn('state', [TaskVmState::Ready, TaskVmState::Parked])->update(['stop_confirmed_at' => null,
                    'error_code' => $code, 'error_message' => 'The task VM park could not be confirmed.']);
            }

            return $projected;
        }));
    }
}
