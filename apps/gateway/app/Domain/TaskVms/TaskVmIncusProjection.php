<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Safe admitted Node input and bounded result projection; no execution. */
final readonly class TaskVmIncusProjection implements DirectOperationProjection
{
    public const array Kinds = ['task_vm.incus_create', 'task_vm.incus_start', 'task_vm.incus_stop', 'task_vm.incus_delete'];

    public function input(Operation $operation): array
    {
        $pin = $operation->input['agent_sha256'] ?? null;
        if (! in_array($operation->kind, self::Kinds, true) || $operation->target_type !== 'task_vm'
            || $operation->node_id === null || ($operation->input['task_vm_id'] ?? null) !== $operation->target_id
            || ! is_string($pin) || preg_match('/\A[a-f0-9]{64}\z/D', $pin) !== 1) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Incus intent has no admitted VM and agent digest.', 422);
        }

        return ['agent_sha256' => $pin, 'intent' => Arr::only($operation->input, ['task_vm_id', 'vm', 'operation'])];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->input($operation);
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        $result = ValidatedData::object($data['result'] ?? []);
        $valid = match ($status) {
            OperationStatus::Succeeded => $result === ['ready' => true] && $code === null,
            OperationStatus::Failed => $code !== null && preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $code) === 1 && $result === ['error_code' => $code],
            OperationStatus::Running, OperationStatus::Cancelled => $result === [] && $code === null,
            default => false,
        };
        if (! $valid) {
            throw new ResourceOperationException('operation.result_invalid', 'The Incus action needs a bounded successful result or refusal.', 422);
        }

        return app(DirectOperation::class)->project($operation, $data, $result, str_replace('_', '-', substr($operation->kind, strlen('task_vm.'))));
    }
}
