<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Models\Operation;
use App\Support\ValidatedData;

/** No result step: the check phase proved every pin, so the result comes from the captured intent. */
final readonly class TaskVmConvergeProjection implements DirectOperationProjection, NativeOperationProjection
{
    /** The end check: the Ansible check phase asserts every package, runtime and tool at its pin. */
    public const string EndCheck = 'Check the task VM role';

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input['intent']), 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = [];
        if (($data['status'] ?? null) === OperationStatus::Succeeded->value) {
            if (NativeRunResult::stepStatus(ValidatedData::object($data['result'] ?? []), self::EndCheck) !== 'succeeded') {
                throw new ResourceOperationException('operation.result_invalid', 'A succeeded converge needs its passed end check.', 422);
            }
            $intent = ValidatedData::object($operation->input['intent']);
            $result = ['converged' => true, 'task_vm_id' => ValidatedData::integer($intent['task_vm_id']), 'recipe_identity' => ValidatedData::string($intent['recipe_identity'])];
        }

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
