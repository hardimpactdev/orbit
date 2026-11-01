<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Native maintenance metadata, followed by normal guarded Tasks admission. */
final readonly class TaskMaintenanceProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input, 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($operation->status->terminal()) {
                return $operation;
            }
            $result = ValidatedData::object($data['result'] ?? []);
            if ($data['status'] === 'succeeded') {
                foreach (['group_id', 'project_id', 'instance_id', 'subtask'] as $field) {
                    if (($result[$field] ?? null) !== $operation->input[$field]) {
                        throw new ResourceOperationException('operation.invalid_result', 'Maintenance result identity differs from its admitted workspace.', 422);
                    }
                }
            }
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            app(TaskMaintenanceContinuation::class)->apply($projected);

            return $projected;
        });
    }
}
