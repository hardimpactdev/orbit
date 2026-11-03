<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Native maintenance metadata, followed by normal guarded Tasks admission. */
final readonly class TaskMaintenanceProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $intent = $operation->input;
        Arr::set($intent, 'maintenance.project.worker.policy', (object) Arr::array($intent, 'maintenance.project.worker.policy'));

        return ['intent' => $intent, 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($operation->status->terminal()) {
                return $operation;
            }
            $native = ValidatedData::object($data['result'] ?? []);
            $result = isset($native['maintenance'])
                ? ValidatedData::object(json_decode(ValidatedData::string($native['maintenance']), true, flags: JSON_THROW_ON_ERROR))
                : [];
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
