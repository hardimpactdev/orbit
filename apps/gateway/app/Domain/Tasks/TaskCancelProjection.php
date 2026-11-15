<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/** The end of an old cancel run, which released a task VM: success settles the group. No cancel starts a run any more (R1 or a copy destroy). */
final readonly class TaskCancelProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return app(TaskCancelIntent::class)->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        if ($data['status'] === OperationStatus::Interrupted->value) {
            $data['status'] = OperationStatus::Failed->value;
        }

        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($operation->status->terminal()) {
                return $operation;
            }
            $group = Task::topLevel()->lockForUpdate()->findOrFail($operation->target_id);
            if ($data['status'] === 'succeeded') {
                app(TaskCancelIntent::class)->input($operation);
            }
            $result = ['group_id' => $group->id, 'instance_id' => $operation->input['instance_id'] ?? null, 'released' => $data['status'] === 'succeeded'];
            if ($data['status'] === 'succeeded') {
                app(TaskGroupEnding::class)->settle($group, $operation->kind);
            }

            return app(DirectOperation::class)->project($operation, $data, $result);
        });
    }
}
