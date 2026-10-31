<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Models\TaskVm;

/** The synchronous Task cleanup caller must compose VM removal in its own native root. */
final readonly class DeleteTaskVmAction
{
    public function execute(TaskVm $vm): void
    {
        throw new ResourceOperationException('operation.kind_unavailable', 'Task VM removal requires its owning native parent.', 409,
            details: ['task_vm_id' => $vm->id]);
    }
}
