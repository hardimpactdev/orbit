<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\OperationCancelGuard;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** A deleted provider VM must finish its owning Node and workspace cleanup. */
final readonly class TaskVmDestroyCancelGuard implements OperationCancelGuard
{
    public function __construct(private OperationChildren $children, private OperationExecutionContract $contract) {}

    public function assertCancellable(Operation $operation): void
    {
        if (! $operation->children()->where('child_key', 'provider-delete')->where('status', OperationStatus::Succeeded->value)->exists()) {
            return;
        }
        $child = $this->children->requireSucceeded($operation, 'provider-delete');
        if ($child->kind !== 'task_vm.'.ValidatedData::string($operation->input['provider']).'_delete'
            || $child->target_type !== 'task_vm' || $child->target_id !== $operation->target_id) {
            throw new ResourceOperationException('operation.child_unproven', 'The provider deletion does not belong to this task VM.', 409);
        }
        $this->contract->assertCompletion($child, $child->result ?? []);

        throw new ResourceOperationException('operation.cancel_refused', 'Provider deletion has succeeded; task VM destruction must finish or resume.', 409);
    }
}
