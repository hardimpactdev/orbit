<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Acknowledgement of admitted identity only; no provider, Node or lifecycle effects. */
final readonly class TaskVmLifecycleIntentProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        if ($operation->kind !== 'task_vm.lifecycle_intent' || $operation->target_type !== 'task_vm'
            || $operation->target_id < 1 || ($operation->input['task_vm_id'] ?? null) !== $operation->target_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The lifecycle acknowledgement needs its admitted VM identity.', 422);
        }

        return ['task_vm_id' => $operation->target_id];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->input($operation);
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        $result = ValidatedData::object($data['result'] ?? []);
        $valid = match ($status) {
            OperationStatus::Succeeded => $result === ['ready' => true] && $code === null,
            OperationStatus::Running, OperationStatus::Cancelled => $result === [] && $code === null,
            default => false,
        };
        if (! $valid) {
            throw new ResourceOperationException('operation.result_invalid', 'The lifecycle acknowledgement needs exactly ready:true.', 422);
        }

        return app(DirectOperation::class)->project($operation, $data, $result, 'lifecycle-intent');
    }
}
