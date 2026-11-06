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

/**
 * Projects the terminal run that builds the nested fleet of a task VM. The Gateway keeps no state of its own for it: the fleet lives in
 * the VM, and the succeeded Operation is the record. A failed run leaves the VMs it made; the next run removes them first.
 */
final readonly class TaskVmFleetBuildProjection implements DirectOperationProjection, NativeOperationProjection
{
    /** The end check: the Ansible wait that asserts Doctor of the nested Gateway is healthy. */
    public const string EndCheck = 'Check the nested fleet';

    public function __construct(private TaskVmFleetBuildIntent $intent) {}

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        $this->intent->owned($operation);

        return TaskVmProviderWorkflowInput::envelope($operation->input);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->intent->owned($operation);
        if ($data['status'] === OperationStatus::Interrupted->value) {
            $data['status'] = OperationStatus::Failed->value;
        }
        if (OperationStatus::from(ValidatedData::string($data['status'])) === OperationStatus::Succeeded
            && NativeRunResult::stepStatus(ValidatedData::object($data['result'] ?? []), self::EndCheck) !== 'succeeded') {
            throw new ResourceOperationException('operation.result_invalid', 'A succeeded fleet build needs its passed end check.', 422);
        }

        return app(DirectOperation::class)->project($operation, $data, []);
    }
}
