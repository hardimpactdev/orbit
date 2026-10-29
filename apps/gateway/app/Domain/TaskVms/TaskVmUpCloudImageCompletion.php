<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Models\UpCloudTaskVmImageBuild;

final readonly class TaskVmUpCloudImageCompletion implements OperationCompletion
{
    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        UpCloudTaskVmImageBuild::query()->whereKey($operation->target_id)->where('state', 'running')->update([
            'state' => $outcome->status === OperationStatus::Succeeded ? 'succeeded' : 'failed', 'finished_at' => now(),
            'failure' => $outcome->status === OperationStatus::Succeeded ? null : json_encode(['phase' => 'operation', 'exit_code' => null, 'stderr' => $outcome->errorCode ?? 'operation.cancelled'], JSON_THROW_ON_ERROR),
        ]);
    }
}
